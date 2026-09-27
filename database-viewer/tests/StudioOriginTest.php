<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use GreyHarbour\DatabaseViewer\Services\StudioOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudioOriginTest extends TestCase
{
    public static function invalidOrigins(): array
    {
        return array_map(fn ($origin) => [$origin], ['*', 'https://*.example.com', 'http://studio.test', 'https://studio.test/', 'https://studio.test/path', 'https://studio.test?q=x', 'https://studio.test#x', 'https://user:pass@studio.test', 'javascript:alert(1)', 'data:text/plain,x', 'https://a.test https://b.test', 'https://a.test:99999', null]);
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_origins_are_rejected(mixed $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StudioOrigin::validate($origin);
    }

    public function test_exact_https_origins_and_canonical_default_port(): void
    {
        $this->assertSame('https://studio.greyharbour.net', StudioOrigin::validate('https://studio.greyharbour.net'));
        $this->assertSame('https://studio.test', StudioOrigin::validate('https://studio.test:443'));
        $this->assertSame('https://studio.test:8443', StudioOrigin::validate('https://studio.test:8443'));
    }
}
