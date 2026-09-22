<?php

namespace Boy132\UserAttributeMapper\Data;

final readonly class ResolvedClaim
{
    public function __construct(public bool $present, public mixed $value = null) {}
}
