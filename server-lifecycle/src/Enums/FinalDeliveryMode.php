<?php

namespace HarbourmasterSam\ServerLifecycle\Enums;

enum FinalDeliveryMode: string
{
    case None = 'none';
    case DownloadLink = 'download_link';
    case AttachmentIfSmall = 'attachment_if_small';
    case AttachmentIfSmallElseLink = 'attachment_if_small_else_link';
}
