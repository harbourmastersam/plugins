<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Backup;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use Throwable;

class ArchiveFailureService
{
    public function __construct(private TemporaryBackupCleanupService $cleanup) {}

    public function failBeforeAdoption(ServerArchive $archive, string $safeMessage, ?Backup $backup = null): void
    {
        if ($archive->status !== LifecycleStatus::ArchiveCancelled) {
            $archive->update(['status' => LifecycleStatus::ArchiveFailed, 'last_error' => $safeMessage]);
        }
        ServerLifecycleState::query()
            ->where('server_id', $archive->original_server_id)
            ->where('current_archive_id', $archive->id)
            ->update(['status' => LifecycleStatus::ArchiveFailed, 'current_archive_id' => null, 'last_error' => $safeMessage]);

        if (! $backup) {
            return;
        }

        try {
            $this->cleanup->handle($archive, $backup);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
