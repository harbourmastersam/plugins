<?php

namespace HarbourmasterSam\UserAttributeMapper\Enums;

enum MappingSourceType: string
{
    case Claim = 'claim';
    case Static = 'static';
}
