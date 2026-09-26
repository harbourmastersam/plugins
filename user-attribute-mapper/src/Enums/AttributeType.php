<?php

namespace HarbourmasterSam\UserAttributeMapper\Enums;

enum AttributeType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Array = 'array';
    case Object = 'object';
}
