<?php

namespace Boy132\UserCreatableServers\OAuth;

class OAuthClaimContext
{
    private bool $oauthLogin = false;

    private ?string $provider = null;

    private bool $claimPresent = false;

    private mixed $limits = null;

    public function capture(string $provider, bool $claimPresent, mixed $limits): void
    {
        $this->oauthLogin = true;
        $this->provider = $provider;
        $this->claimPresent = $claimPresent;
        $this->limits = $limits;
    }

    public function isOAuthLogin(): bool
    {
        return $this->oauthLogin;
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
