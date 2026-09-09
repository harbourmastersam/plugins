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
        if (
            config('user-creatable-servers.oidc_sync.enabled')
            && $request->routeIs('auth.oauth.callback')
            && $request->route('driver') === config('user-creatable-servers.oidc_sync.provider', 'authentik')
        ) {
            app(SocialiteWasCalled::class)->extendSocialite('authentik', AuthentikProvider::class);

            /** @var SocialiteManager $socialite */
            $socialite = app(SocialiteFactory::class);
            $socialite->forgetDrivers();
        }

        return $next($request);
    }
}
