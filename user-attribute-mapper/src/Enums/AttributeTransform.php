<?php

namespace HarbourmasterSam\UserAttributeMapper\Enums;

enum AttributeTransform: string
{
    case Trim = 'trim';
    case Lowercase = 'lowercase';
    case Uppercase = 'uppercase';
    case Prefix = 'prefix';
    case Suffix = 'suffix';
    case Replace = 'replace';
}
