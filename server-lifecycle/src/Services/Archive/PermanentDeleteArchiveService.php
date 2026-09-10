<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\User;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PermanentDeleteArchiveService
{
    public function __construct(private ArchiveStorageInterface $storage) {}

    public function handleManual(ServerArchive $archive, User $actor): void
    {
        $isOwnerAllowed = config('server-lifecycle.users_may_delete')
            && (int) $archive->owner_id === (int) $actor->id;
        abort_unless($actor->root_admin || $isOwnerAllowed, 403);

        $this->handle($archive, true, $actor->root_admin ? 'manual_admin' : 'manual_owner');
    }
    public function handle(ServerArchive $archive, bool $manualOverride = false, string $reason = 'retention_expired'): void
    {
        Cache::lock("server-lifecycle:delete:$archive->id", 300)->block(5, function () use ($archive, $manualOverride, $reason): void {
            $archive->refresh();
            $allowed = $manualOverride
                ? [LifecycleStatus::Archived, LifecycleStatus::DeletionWarning, LifecycleStatus::Restored, LifecycleStatus::RestoreFailed, LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed]
                : [LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed];
            if (! in_array($archive->status, $allowed, true)) {
                throw new RuntimeException('Archive cannot be permanently deleted in its current lifecycle state.');
            }
            if (! $manualOverride && ! $archive->final_delivery_sent_at) {
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
                    'retry_count' => $archive->retry_count + 1,
                    'retry_after' => now()->addMinutes(min(60, 2 ** min(6, $archive->retry_count))),
                ]);
                throw $exception;
            }

            $archive->update([
                'status' => LifecycleStatus::Deleted,
                'remote_object_deleted_at' => now(),
                'deletion_reason' => $reason,
                'manifest' => null,
                'object_key' => null,
                'checksum' => null,
                'original_node' => null,
                'original_allocations' => null,
                'policy_snapshot' => [],
                'restore_backup_id' => null,
                'last_error' => null,
                'retry_after' => null,
            ]);
        });
    }
}
