<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PermanentDeleteArchiveService
{
    public function __construct(private ArchiveStorageInterface $storage) {}
    public function handle(ServerArchive $archive): void
    {
        Cache::lock("server-lifecycle:delete:$archive->id", 300)->block(5, function () use ($archive): void {
            $archive->refresh();
            if (! in_array($archive->status, [LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed], true)) return;
            try { $this->storage->delete($archive); if ($this->storage->exists($archive)) throw new RuntimeException('Archive object remains after delete request.'); }
            catch (\Throwable $e) { $archive->update(['status' => LifecycleStatus::DeleteFailed, 'last_error' => 'Remote object deletion failed and remains retryable.']); throw $e; }
            $archive->update(['status' => LifecycleStatus::Deleted, 'remote_object_deleted_at' => now(), 'manifest' => null, 'object_key' => null, 'checksum' => null, 'original_node' => null, 'original_allocations' => null, 'policy_snapshot' => [], 'last_error' => null]);
        });
    }
}
