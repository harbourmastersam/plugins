<?php

namespace HarbourmasterSam\UserAttributeMapper\OAuth;

class OAuthClaimContext
{
    private bool $observed = false;
    private bool $inspectable = false;
    private ?string $provider = null;
    /** @var array<string, mixed> */
    private array $claims = [];

    /** @param array<string, mixed>|null $claims */
    public function capture(string $provider, ?array $claims): void
    {
        $this->observed = true;
        $this->provider = $provider;
        $this->inspectable = $claims !== null;
        $this->claims = $claims ?? [];
    }

    public function observed(): bool { return $this->observed; }
    public function inspectable(): bool { return $this->inspectable; }
    public function provider(): ?string { return $this->provider; }
    /** @return array<string, mixed> */
    public function claims(): array { return $this->claims; }
}
