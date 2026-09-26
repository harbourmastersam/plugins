<?php

namespace HarbourmasterSam\UserAttributeMapper\Enums;

enum MissingClaimBehavior: string
{
    case Preserve = 'preserve';
    case Clear = 'clear';
}
