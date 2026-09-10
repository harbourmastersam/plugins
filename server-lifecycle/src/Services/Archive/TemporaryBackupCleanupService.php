<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Backup;
use App\Services\Backups\DeleteBackupService;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;

class TemporaryBackupCleanupService
{
    public function __construct(private DeleteBackupService $backupDeletion) {}

    public function handle(ServerArchive $archive, Backup $backup): bool
    {
        if ($archive->backup_id === null
            || (int) $archive->backup_id !== (int) $backup->id
            || (int) $archive->original_server_id !== (int) $backup->server_id
            || ! hash_equals((string) $archive->original_backup_uuid, (string) $backup->uuid)
            || ! $backup->exists) {
            return false;
        }

        $backup->update(['is_locked' => false]);
        $this->backupDeletion->handle($backup);

        return true;
    }
}
