<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use App\Services\Servers\ServerDeletionService;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RetryServerDeletionService
{
    public function __construct(
        private ArchiveStorageInterface $storage,
        private ServerDeletionService $deletion,
    ) {}

    public function handle(ServerArchive $archive): void
    {
        Cache::lock("server-lifecycle:archive:$archive->original_server_id", 300)
            ->block(5, function () use ($archive): void {
                $archive->refresh();
                if ($archive->status !== LifecycleStatus::ArchiveCreatedDeleteFailed) {
                    return;
                }

                if (! $archive->object_key || ! $this->storage->exists($archive)) {
                    throw new RuntimeException('The adopted archive cannot be confirmed; server deletion is refused.');
                }

                $server = Server::query()->find($archive->original_server_id);
                if ($server) {
                    $server->unsetRelation('backups')->refresh();
                    try {
                        $this->deletion->handle($server);
                    } catch (\Throwable $exception) {
                        $archive->update([
                            'retry_count' => $archive->retry_count + 1,
                            'retry_after' => now()->addMinutes(min(60, 2 ** min(6, $archive->retry_count))),
                            'last_error' => 'Native server deletion retry failed; archive retained.',
                        ]);
                        throw $exception;
                    }
                }

                $retention = data_get($archive->policy_snapshot, 'archive_retention_minutes');
                $archive->update([
                    'status' => LifecycleStatus::Archived,
                    'archived_at' => $archive->archived_at ?? now(),
                    'retention_expires_at' => $archive->retention_expires_at
                        ?? ($retention === null ? null : now()->addMinutes((int) $retention)),
                    'last_error' => null,
                    'retry_after' => null,
                ]);
            });
    }
}
