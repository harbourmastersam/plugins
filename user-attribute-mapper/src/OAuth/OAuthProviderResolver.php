<?php

namespace Boy132\UserAttributeMapper\OAuth;

use App\Extensions\OAuth\OAuthService;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;

/** Isolates the only dependency on Pelican's internal OAuth registry. */
class OAuthProviderResolver
{
    public function __construct(private readonly OAuthService $oauth, private readonly Factory $socialite) {}

    public function available(string $provider): bool
    {
        $schema = $this->oauth->get($provider);
        return $schema !== null && $schema->isEnabled();
    }

    public function resolve(string $provider): Provider
    {
        return $this->socialite->driver($provider);
    }

    /** @return array<string, string> */
    public function options(): array
    {
        // OAuthService's collection accessor has changed between Pelican releases.
        // Keep that compatibility surface isolated here; callback resolution uses get().
        $schemas = [];
        foreach (['all', 'getAll', 'getProviders', 'getSchemas'] as $method) {
            if (method_exists($this->oauth, $method)) {
                $schemas = $this->oauth->{$method}();
                break;
            }
        }

        return collect($schemas)->filter(fn ($schema) => $schema->isEnabled())->mapWithKeys(fn ($schema) => [$schema->getId() => $schema->getName()])->all();
    }
}
