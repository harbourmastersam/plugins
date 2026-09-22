<?php

namespace Boy132\UserAttributeMapper\Http\Middleware;

use Boy132\UserAttributeMapper\OAuth\CapturingSocialiteProvider;
use Boy132\UserAttributeMapper\OAuth\OAuthClaimContext;
use Boy132\UserAttributeMapper\OAuth\OAuthProviderResolver;
use Closure;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\SocialiteManager;
use Symfony\Component\HttpFoundation\Response;

class CaptureOAuthClaims
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->routeIs('auth.oauth.callback') || !is_string($provider = $request->route('driver')) || $provider === '') return $next($request);
        $resolver = app(OAuthProviderResolver::class);
        if (!$resolver->available($provider)) return $next($request);
        /** @var SocialiteManager $manager */
        $manager = app(Factory::class);
        $original = $resolver->resolve($provider);
        $context = app(OAuthClaimContext::class);
        $manager->extend($provider, fn () => new CapturingSocialiteProvider($original, $context, $provider));
        $manager->forgetDrivers();
        return $next($request);
    }
}
