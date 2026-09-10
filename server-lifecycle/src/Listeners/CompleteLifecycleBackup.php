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
        $archive = ServerArchive::query()->where('backup_id', $backup->id)->where('status', LifecycleStatus::Archiving)->first();
        if (! $archive) return;
        try { $this->service->handle($archive, $backup); }
        catch (\Throwable) { $archive->update(['status' => LifecycleStatus::Failed, 'last_error' => $archive->last_error ?: 'Archive verification failed; live server retained.']); }
    }
}
