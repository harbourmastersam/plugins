<?php

namespace Boy132\UserCreatableServers\Http\Middleware;

use Boy132\UserCreatableServers\OAuth\AuthentikProvider;
use Closure;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthentikProvider
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('user-creatable-servers.oidc_sync.enabled')) {
            return $next($request);
        }

        if (!$request->routeIs('auth.oauth.callback')) {
            return $next($request);
        }

        $provider = config(
            'user-creatable-servers.oidc_sync.provider',
            'authentik'
        );

        if ($request->route('driver') !== $provider) {
            return $next($request);
        }

        /*
         * Resolve Socialite first because its provider is deferred.
         * This ensures SocialiteProviders Manager has registered the
         * dependencies required by SocialiteWasCalled.
         */
        /** @var SocialiteManager $socialite */
        $socialite = app(SocialiteFactory::class);

        app(SocialiteWasCalled::class)->extendSocialite(
            $provider,
            AuthentikProvider::class
        );

        $socialite->forgetDrivers();

        return $next($request);
    }
}
