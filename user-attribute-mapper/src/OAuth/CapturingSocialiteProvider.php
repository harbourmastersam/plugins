<?php

namespace HarbourmasterSam\UserAttributeMapper\OAuth;

use HarbourmasterSam\UserAttributeMapper\Services\SensitiveClaimPolicy;
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
            $this->context->capture($this->providerId, is_array($raw) ? (new SensitiveClaimPolicy())->filter($raw) : null);
        } catch (Throwable) {
            $this->context->capture($this->providerId, null);
        }
        return $user;
    }

    public function __call(string $method, array $parameters): mixed { return $this->inner->{$method}(...$parameters); }

}
