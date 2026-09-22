<?php

namespace Boy132\UserAttributeMapper\Providers;

use Boy132\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use Boy132\UserAttributeMapper\Console\Commands\InspectUserAttributeRegistryCommand;
use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Events\RegisterUserAttributes;
use Boy132\UserAttributeMapper\Http\Middleware\CaptureOAuthClaims;
use Boy132\UserAttributeMapper\Listeners\SyncMappedAttributes;
use Boy132\UserAttributeMapper\OAuth\OAuthClaimContext;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class UserAttributeMapperPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserAttributeRegistryContract::class, UserAttributeRegistry::class);
        $this->app->scoped(OAuthClaimContext::class, fn () => new OAuthClaimContext());

        if ($this->app->runningInConsole()) {
            $this->commands([InspectUserAttributeRegistryCommand::class]);
        }
    }

    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', CaptureOAuthClaims::class);
        Event::listen(Login::class, SyncMappedAttributes::class);

        // Pelican registers all enabled plugin providers before Laravel finishes
        // booting. Extension listeners are installed in register(), so this final
        // callback is independent of plugin provider order.
        $this->app->booted(function (): void {
            if (!$this->app->bound(UserAttributeRegistryContract::class)) {
                Log::warning('User Attribute Mapper registration skipped: registry service is not bound.');

                return;
            }

            $registry = $this->app->make(UserAttributeRegistryContract::class);
            $this->app->make(PelicanUserAttributeProvider::class)->register($registry);
            Event::dispatch(new RegisterUserAttributes($registry));
        });
    }
}
