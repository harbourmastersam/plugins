<?php

namespace HarbourmasterSam\UserAttributeMapper\Events;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;

final class RegisterUserAttributes
{
    public function __construct(
        public readonly UserAttributeRegistryContract $registry,
    ) {}
}
