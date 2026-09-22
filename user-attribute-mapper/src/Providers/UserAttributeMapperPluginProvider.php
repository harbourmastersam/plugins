<?php

namespace Boy132\UserAttributeMapper\Providers;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Http\Middleware\CaptureOAuthClaims;
use Boy132\UserAttributeMapper\Listeners\SyncMappedAttributes;
use Boy132\UserAttributeMapper\OAuth\OAuthClaimContext;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
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
    }
}
