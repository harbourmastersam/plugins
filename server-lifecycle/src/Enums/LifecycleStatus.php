<?php

namespace HarbourmasterSam\ServerLifecycle\Enums;

enum LifecycleStatus: string
{
    case Active = 'active';
    case Warning = 'warning';
    case Archiving = 'archiving';
    case ArchiveCreatedDeleteFailed = 'archive_created_delete_failed';
    case Archived = 'archived';
    case Restoring = 'restoring';
    case RestoreFailed = 'restore_failed';
    case Restored = 'restored';
    case DeletionWarning = 'deletion_warning';
    case PendingDeletion = 'pending_deletion';
    case Deleting = 'deleting';
    case DeleteFailed = 'delete_failed';
    case Deleted = 'deleted';
    case Failed = 'failed';
}
