<?php

namespace HarbourmasterSam\UserAttributeMapper\Data;

final readonly class ResolvedClaim
{
    public function __construct(public bool $present, public mixed $value = null) {}
}
