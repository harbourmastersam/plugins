<?php

namespace Boy132\UserAttributeMapper\Events;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;

final class RegisterUserAttributes
{
    public function __construct(
        public readonly UserAttributeRegistryContract $registry,
    ) {}
}
