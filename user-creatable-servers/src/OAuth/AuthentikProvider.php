<?php

namespace Boy132\UserCreatableServers\OAuth;

use SocialiteProviders\Authentik\Provider;

class AuthentikProvider extends Provider
{
    protected function mapUserToObject(array $user)
    {
        $claimName = config('user-creatable-servers.oidc_sync.claim', 'pelican_limits');

        app(OAuthClaimContext::class)->capture(
            provider: 'authentik',
            claimPresent: array_key_exists($claimName, $user),
            limits: $user[$claimName] ?? null,
        );

        return parent::mapUserToObject($user);
    }
}
