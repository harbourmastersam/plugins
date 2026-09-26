<?php

namespace HarbourmasterSam\UserAttributeMapper\Enums;

enum AttributeMutationResult: string
{
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Cleared = 'cleared';
}
