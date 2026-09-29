<?php

namespace GreyHarbour\DatabaseViewer\Enums;

enum SqlAccessMode: string
{
    case ReadOnly = 'read_only';
    case Full = 'full';
}
