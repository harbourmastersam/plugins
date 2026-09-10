<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use App\Services\Servers\ServerDeletionService;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Status\FreshWingsServerStatusService;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class StartRestoreService
{
    public function __construct(
        private ArchiveStorageInterface $storage,
        private ServerCreationService $creation,
        private ServerDeletionService $deletion,
        private RestoreDeploymentService $deployments,
        private RestoreAllocationValidationService $allocationValidation,
        private ServerCreationPayloadService $payloads,
        private ContinueRestoreService $continueRestore,
        private FreshWingsServerStatusService $statuses,
    ) {}

    public function handle(ServerArchive $archive, ?int $ownerId = null): Server
    {
        return Cache::lock("server-lifecycle:restore:$archive->id", 300)
            ->block(5, fn (): Server => $this->startWhileLocked($archive, $ownerId));
    }

    private function startWhileLocked(ServerArchive $archive, ?int $ownerId): Server
    {
        $archive->refresh();
        if (! in_array($archive->status, [LifecycleStatus::Archived, LifecycleStatus::ArchiveSuperseded, LifecycleStatus::RestoreFailed], true)) {
            throw new RuntimeException('Archive cannot currently be restored.');
        }
        if (! $archive->object_key || ! $this->storage->exists($archive)) {
            throw new RuntimeException('Archive object is unavailable.');
        }

        if ($archive->status === LifecycleStatus::RestoreFailed && $archive->restored_server_id) {
            $existing = Server::query()->find($archive->restored_server_id);
            if ($existing) {
                $this->assertReusable($archive, $existing, $ownerId);
                $archive->update(['status' => LifecycleStatus::Restoring, 'last_error' => null]);
                $this->continueRestore->handle($archive, $existing);

                return $existing;
            }
            $archive->update(['restored_server_id' => null, 'restore_backup_id' => null]);
        }

        $server = null;
        try {
            $plan = $this->deployments->plan($archive);
            $payload = $this->payloads->build($archive, $plan, $ownerId);
            // Passing no DeploymentObject makes this explicit plan authoritative.
            $server = $this->creation->handle($payload);
            $this->allocationValidation->assertAssigned($server, $plan);
        } catch (Throwable $exception) {
            if ($server) {
                try {
                    $this->deletion->handle($server);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $archive->update([
                'status' => LifecycleStatus::RestoreFailed,
                'restored_server_id' => $server?->exists ? $server->id : null,
                'last_error' => 'Restore provisioning or allocation validation failed; archive retained.',
            ]);
            throw $exception;
        }

        $archive->update([
            'status' => LifecycleStatus::Restoring,
            'restored_server_id' => $server->id,
            'last_error' => null,
        ]);

        return $server;
    }

    private function assertReusable(ServerArchive $archive, Server $server, ?int $ownerId): void
    {
        $manifest = $archive->manifest;
        $expectedOwner = $ownerId ?? $archive->owner_id;
        if ((int) $server->owner_id !== (int) $expectedOwner
            || (int) $server->egg_id !== (int) $manifest['egg_id']
            || $server->isInConflictState()
            || $this->statuses->get($server) !== AuthoritativeServerState::Offline) {
            throw new RuntimeException('The existing failed restore server is not safe to reuse; administrator recovery is required.');
        }
    }
}
