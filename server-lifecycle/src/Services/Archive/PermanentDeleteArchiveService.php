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
            if (! in_array($archive->status, [LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed], true)) {
                return;
            }
            if (! $archive->final_delivery_sent_at) {
                throw new RuntimeException('Required final delivery has not succeeded; automatic deletion is refused.');
            }

            $expectedKey = "$archive->original_server_uuid/$archive->original_backup_uuid.tar.gz";
            if (blank($archive->object_key) || ! hash_equals($expectedKey, $archive->object_key)) {
                throw new RuntimeException('Archive object key is invalid; deletion is refused.');
            }

            $archive->update(['status' => LifecycleStatus::Deleting]);
            try {
                $this->storage->delete($archive);
                if ($this->storage->exists($archive)) {
                    throw new RuntimeException('Archive object remains after delete request.');
                }
            } catch (\Throwable $exception) {
                $archive->update([
                    'status' => LifecycleStatus::DeleteFailed,
                    'last_error' => 'Remote object deletion failed and remains retryable.',
                ]);
                throw $exception;
            }

            $archive->update([
                'status' => LifecycleStatus::Deleted,
                'remote_object_deleted_at' => now(),
                'deletion_reason' => 'retention_expired',
                'manifest' => null,
                'object_key' => null,
                'checksum' => null,
                'original_node' => null,
                'original_allocations' => null,
                'policy_snapshot' => [],
                'restore_backup_id' => null,
                'last_error' => null,
            ]);
        });
    }
}
