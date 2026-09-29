<?php

namespace GreyHarbour\DatabaseViewer\Services;

final class BrokerLimits
{
    public const SCHEMA_STATEMENT_COUNT = 6;

    public const MAX_STATEMENT_BYTES = 65536;

    public const MAX_BATCH_STATEMENTS = 100;

    public const MAX_REQUEST_BYTES = 1048576;

    public const MAX_RESPONSE_BYTES = 5242880;

    public const CONNECTION_TIMEOUT_SECONDS = 3;

    public const STATEMENT_TIMEOUT_SECONDS = 30;

    public static function statementFits(string $statement): bool
    {
        return strlen($statement) <= self::MAX_STATEMENT_BYTES;
    }

    private function __construct() {}
}
