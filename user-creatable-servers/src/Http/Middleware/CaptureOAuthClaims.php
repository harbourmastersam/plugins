<?php

namespace Boy132\UserCreatableServers\Http\Middleware;

use App\Extensions\OAuth\OAuthService;
use Boy132\UserCreatableServers\OAuth\CapturingSocialiteProvider;
use Boy132\UserCreatableServers\OAuth\OAuthClaimContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use Symfony\Component\HttpFoundation\Response;

class CaptureOAuthClaims
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('user-creatable-servers.oauth_sync.enabled') || !$request->routeIs('auth.oauth.callback')) {
            return $next($request);
        }

        $providerId = $request->route('driver');

        if (!is_string($providerId) || $providerId === '' || !$this->providerSelected($providerId)) {
            return $next($request);
        }

        // Resolve this at callback time so schemas registered by other plugins are visible.
        $schema = app(OAuthService::class)->get($providerId);

        if ($schema === null || !$schema->isEnabled()) {
            return $next($request);
        }

        /** @var SocialiteManager $socialite */
        $socialite = app(SocialiteFactory::class);

        // Preserve Pelican's fully configured provider, including plugin-specific settings.
        $original = $socialite->driver($providerId);
        $context = app(OAuthClaimContext::class);
        $claimName = (string) config('user-creatable-servers.oauth_sync.claim', 'pelican_limits');

        $socialite->extend(
            $providerId,
            fn () => new CapturingSocialiteProvider($original, $context, $providerId, $claimName),
        );
        $socialite->forgetDrivers();

        return $next($request);
    }

    private function providerSelected(string $providerId): bool
    {
        $providers = config('user-creatable-servers.oauth_sync.providers', []);
        $providers = is_array($providers) ? $providers : explode(',', (string) $providers);
        $providers = array_map('trim', $providers);

        return in_array('*', $providers, true) || in_array($providerId, $providers, true);
    }
}
