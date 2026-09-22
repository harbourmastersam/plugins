<?php

namespace Boy132\UserAttributeMapper\Enums;

enum MissingClaimBehavior: string
{
    case Preserve = 'preserve';
    case Clear = 'clear';
}
