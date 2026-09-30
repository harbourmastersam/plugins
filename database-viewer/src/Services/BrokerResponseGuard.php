<?php

namespace GreyHarbour\DatabaseViewer\Services;

use RuntimeException;

class BrokerResponseGuard
{
    public function encodeData(mixed $data): string
    {
        $encoded = json_encode(['data' => $data], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > BrokerLimits::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Database response exceeds the permitted size.');
        }

        return $encoded;
    }
}
