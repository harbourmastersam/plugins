<?php

namespace HarbourmasterSam\ServerLifecycle\Enums;

enum AuthoritativeServerState
{
    case Offline;
    case Active;
    case InactiveUnsafe;
    case ConfirmedMissing;
}
