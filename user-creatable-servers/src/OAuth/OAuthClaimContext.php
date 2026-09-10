<?php

namespace Boy132\UserCreatableServers\OAuth;

class OAuthClaimContext
{
    private bool $oauthCallbackObserved = false;

    private ?string $provider = null;

    private bool $claimPresent = false;

    private bool $rawAttributesInspectable = false;

    private mixed $limits = null;

    public function capture(
        string $provider,
        bool $rawAttributesInspectable,
        bool $claimPresent = false,
        mixed $limits = null,
    ): void
    {
        $this->oauthCallbackObserved = true;
        $this->provider = $provider;
        $this->rawAttributesInspectable = $rawAttributesInspectable;
        $this->claimPresent = $claimPresent;
        $this->limits = $limits;
    }

    public function isOAuthLogin(): bool
    {
        return $this->oauthCallbackObserved;
    }

    public function rawAttributesInspectable(): bool
    {
        return $this->rawAttributesInspectable;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function claimPresent(): bool
    {
        return $this->claimPresent;
    }

    public function limits(): mixed
    {
        return $this->limits;
    }
}
