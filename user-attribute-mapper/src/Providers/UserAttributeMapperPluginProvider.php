<?php

namespace HarbourmasterSam\UserAttributeMapper\Providers;

use HarbourmasterSam\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use HarbourmasterSam\UserAttributeMapper\Console\Commands\InspectUserAttributeRegistryCommand;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Events\RegisterUserAttributes;
use HarbourmasterSam\UserAttributeMapper\Http\Middleware\CaptureOAuthClaims;
use HarbourmasterSam\UserAttributeMapper\Listeners\SyncMappedAttributes;
use HarbourmasterSam\UserAttributeMapper\OAuth\OAuthClaimContext;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeRegistry;
use HarbourmasterSam\UserAttributeMapper\Services\MappingCandidateResolver;
use HarbourmasterSam\UserAttributeMapper\Services\ClaimPathResolver;
use HarbourmasterSam\UserAttributeMapper\Services\AttributeTransformationService;
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
        $this->app->singleton(MappingCandidateResolver::class, fn ($app) => new MappingCandidateResolver($app->make(ClaimPathResolver::class), $app->make(AttributeTransformationService::class)));

        if ($this->app->runningInConsole()) {
            $this->commands([InspectUserAttributeRegistryCommand::class]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(plugin_path('user-attribute-mapper', 'resources/views'), 'user-attribute-mapper');

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
