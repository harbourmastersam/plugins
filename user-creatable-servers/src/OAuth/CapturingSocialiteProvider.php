<?php

namespace Boy132\UserCreatableServers\OAuth;

use Illuminate\Support\Arr;
use Laravel\Socialite\Contracts\Provider;
use Throwable;

class CapturingSocialiteProvider implements Provider
{
    public function __construct(
        private readonly Provider $inner,
        private readonly OAuthClaimContext $context,
        private readonly string $providerId,
        private readonly string $claimName,
    ) {}

    public function redirect()
    {
        return $this->inner->redirect();
    }

    public function user()
    {
        $oauthUser = $this->inner->user();

        try {
            $raw = $this->rawAttributes($oauthUser);

            if (!is_array($raw)) {
                $this->context->capture($this->providerId, false);

                return $oauthUser;
            }

            $claimPresent = Arr::has($raw, $this->claimName);

            $this->context->capture(
                provider: $this->providerId,
                rawAttributesInspectable: true,
                claimPresent: $claimPresent,
                limits: $claimPresent ? data_get($raw, $this->claimName) : null,
            );
        } catch (Throwable) {
            // Claim inspection must never prevent the underlying OAuth login.
            $this->context->capture($this->providerId, false);
        }

        return $oauthUser;
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->inner->{$method}(...$parameters);
    }

    private function rawAttributes(object $oauthUser): ?array
    {
        if (method_exists($oauthUser, 'getRaw')) {
            $raw = $oauthUser->getRaw();

            return is_array($raw) ? $raw : null;
        }

        // get_object_vars only exposes public properties in this scope.
        $publicProperties = get_object_vars($oauthUser);
        $raw = $publicProperties['user'] ?? null;

        return is_array($raw) ? $raw : null;
    }
}
