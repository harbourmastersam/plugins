<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\BrokerResponseGuard;
use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BrokerResponseGuardTest extends TestCase
{
    public function test_exact_complete_envelope_limit_is_accepted(): void
    {
        $guard = new BrokerResponseGuard();
        $data = [['value' => '']];
        $base = strlen(json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $data[0]['value'] = str_repeat('x', BrokerLimits::MAX_RESPONSE_BYTES - $base);

        $encoded = $guard->encodeData($data);

        $this->assertSame(BrokerLimits::MAX_RESPONSE_BYTES, strlen($encoded));
    }

    public function test_one_byte_over_complete_envelope_limit_is_rejected(): void
    {
        $guard = new BrokerResponseGuard();
        $data = [['value' => '']];
        $base = strlen(json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $data[0]['value'] = str_repeat('x', BrokerLimits::MAX_RESPONSE_BYTES - $base + 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database response exceeds the permitted size.');
        $guard->encodeData($data);
    }

    public function test_complete_envelope_json_encoding_failure_is_rejected(): void
    {
        $this->expectException(JsonException::class);
        (new BrokerResponseGuard())->encodeData([['value' => "\xB1\x31"]]);
    }
}
