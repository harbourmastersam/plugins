<?php

namespace GreyHarbour\DatabaseViewer\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class ViewerSessionHandle
{
    public function __construct(
        public string $channel,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $maxExpiresAt,
        public CarbonImmutable $serverNow,
    ) {}
}
