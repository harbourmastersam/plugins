<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Backup;
use App\Services\Servers\ServerDeletionService;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use RuntimeException;

class CompleteArchiveService
{
    public function __construct(private ArchiveStorageInterface $storage, private AdoptBackupAsArchiveService $adopter, private ServerDeletionService $deletion) {}
    public function handle(ServerArchive $archive, Backup $backup): void
    {
        if (! $backup->completed_at || ! $backup->is_successful || $backup->bytes < 1 || blank($backup->checksum)) throw new RuntimeException('Backup completion verification failed.');
        $metadata = $this->storage->head($archive);
        if ($metadata->bytes !== (int) $backup->bytes) throw new RuntimeException('Remote archive size does not match the completed backup.');
        $server = $backup->server;
        $this->adopter->handle($archive, $backup);
        $server->unsetRelation('backups')->refresh();
        try { $this->deletion->handle($server); }
        catch (\Throwable $exception) { $archive->update(['status' => LifecycleStatus::ArchiveCreatedDeleteFailed, 'last_error' => 'Native server deletion failed; archive retained.']); throw $exception; }
        $retention = data_get($archive->policy_snapshot, 'archive_retention_minutes');
        $archive->update(['status' => LifecycleStatus::Archived, 'archived_at' => now(), 'retention_expires_at' => $retention === null ? null : now()->addMinutes((int) $retention), 'last_error' => null]);
    }
}
