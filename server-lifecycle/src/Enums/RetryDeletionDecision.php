<?php

namespace HarbourmasterSam\ServerLifecycle\Enums;

enum RetryDeletionDecision
{
    case ContinueCleanup;
    case RevokeAuthority;
}
