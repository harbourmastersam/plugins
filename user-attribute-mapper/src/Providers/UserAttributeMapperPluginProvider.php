<?php

namespace Boy132\UserAttributeMapper\Providers;

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
    }

    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', CaptureOAuthClaims::class);
        Event::listen(Login::class, SyncMappedAttributes::class);

        // Pelican registers every enabled plugin provider before it boots the
        // application, so listeners installed by optional plugins are ready here.
        $this->app->booted(function (): void {
            if (!$this->app->bound(UserAttributeRegistryContract::class)) {
                Log::warning('User Attribute Mapper registration skipped: registry service is not bound.');

                return;
            }

            $registry = $this->app->make(UserAttributeRegistryContract::class);
            Event::dispatch(new RegisterUserAttributes($registry));

            $definitions = $registry->all();
            Log::debug('User Attribute Mapper registration completed.', [
                'registered_attributes' => $definitions->count(),
                'owners' => $definitions->pluck('owner')->unique()->values()->all(),
            ]);
        });
    }
}
