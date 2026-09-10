<?php

namespace HarbourmasterSam\ServerLifecycle\Listeners;

use App\Events\Server\BackupCompleted;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Archive\CompleteArchiveService;

class CompleteLifecycleBackup
{
    public function __construct(private CompleteArchiveService $service) {}
    public function handle(BackupCompleted $event): void
    {
        $backup = $event->backup;
        $archive = ServerArchive::query()
            ->where('backup_id', $backup->id)
            ->whereIn('status', [LifecycleStatus::Archiving, LifecycleStatus::ArchiveCancelled])
            ->first();
        if (! $archive) return;
        try {
            $this->service->handle($archive, $backup);
        } catch (\Throwable $exception) {
            report($exception);

            $archive->refresh();
            if (! in_array($archive->status, [
                LifecycleStatus::ArchiveCreatedDeleteFailed,
                LifecycleStatus::ArchiveCancelled,
                LifecycleStatus::ArchiveFailed,
            ], true)) {
                $archive->update([
                    'status' => LifecycleStatus::ArchiveFailed,
                    'last_error' => 'Archive verification failed; live server retained.',
                ]);
            }
        }
    }
}
