<?php

namespace HarbourmasterSam\UserAttributeMapper\OAuth;

use Laravel\Socialite\Contracts\Provider;
use Throwable;

class CapturingSocialiteProvider implements Provider
{
    public function __construct(private readonly Provider $inner, private readonly OAuthClaimContext $context, private readonly string $providerId) {}

    public function redirect() { return $this->inner->redirect(); }

    public function user()
    {
        $user = $this->inner->user();
        try {
            $raw = method_exists($user, 'getRaw') ? $user->getRaw() : (get_object_vars($user)['user'] ?? null);
            $this->context->capture($this->providerId, is_array($raw) ? $this->withoutCredentials($raw) : null);
        } catch (Throwable) {
            $this->context->capture($this->providerId, null);
        }
        return $user;
    }

    public function __call(string $method, array $parameters): mixed { return $this->inner->{$method}(...$parameters); }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function withoutCredentials(array $attributes): array
    {
        $credentials = ['access_token', 'refresh_token', 'id_token', 'client_secret', 'authorization_code', 'code', 'token'];
        foreach ($attributes as $key => $value) {
            if (in_array(strtolower((string) $key), $credentials, true)) {
                unset($attributes[$key]);
            } elseif (is_array($value)) {
                $attributes[$key] = $this->withoutCredentials($value);
            }
        }
        return $attributes;
    }
}
