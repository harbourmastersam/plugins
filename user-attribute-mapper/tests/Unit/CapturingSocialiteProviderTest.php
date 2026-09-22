<?php

namespace Boy132\UserAttributeMapper\Tests\Unit;

use Boy132\UserAttributeMapper\OAuth\CapturingSocialiteProvider;
use Boy132\UserAttributeMapper\OAuth\OAuthClaimContext;
use Laravel\Socialite\Contracts\Provider;
use PHPUnit\Framework\TestCase;

class CapturingSocialiteProviderTest extends TestCase
{
    public function test_user_is_called_once_and_exact_instance_and_raw_claims_are_preserved(): void
    {
        $oauthUser = new class { public function getRaw(): array { return ['limits' => ['cpu' => 800], 'access_token' => 'secret']; } };
        $inner = $this->createMock(Provider::class);
        $inner->expects(self::once())->method('user')->willReturn($oauthUser);
        $context = new OAuthClaimContext();
        $result = (new CapturingSocialiteProvider($inner, $context, 'dynamic-provider'))->user();
        self::assertSame($oauthUser, $result);
        self::assertSame(['limits' => ['cpu' => 800]], $context->claims());
        self::assertSame('dynamic-provider', $context->provider());
    }

    public function test_unavailable_raw_attributes_are_non_destructive(): void
    {
        $oauthUser = new class {};
        $inner = $this->createMock(Provider::class);
        $inner->method('user')->willReturn($oauthUser);
        $context = new OAuthClaimContext();
        (new CapturingSocialiteProvider($inner, $context, 'provider'))->user();
        self::assertFalse($context->inspectable());
    }
}
