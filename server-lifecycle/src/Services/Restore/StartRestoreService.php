<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Services\Servers\ServerCreationService;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class StartRestoreService
{
    public function __construct(
        private ArchiveStorageInterface $storage,
        private ServerCreationService $creation,
        private RestoreDeploymentService $deployments,
        private ServerCreationPayloadService $payloads,
    ) {}

    public function handle(ServerArchive $archive, ?int $ownerId = null): object
    {
        return Cache::lock("server-lifecycle:restore:$archive->id", 300)
            ->block(5, function () use ($archive, $ownerId): object {
                $archive->refresh();
                if (! in_array($archive->status, [LifecycleStatus::Archived, LifecycleStatus::RestoreFailed], true)) {
                    throw new RuntimeException('Archive cannot currently be restored.');
                }
                if (! $archive->object_key || ! $this->storage->exists($archive)) {
                    throw new RuntimeException('Archive object is unavailable.');
                }

                try {
                    $plan = $this->deployments->plan($archive);
                    $payload = $this->payloads->build($archive, $plan, $ownerId);
                    // ServerCreationService rechecks and locks these currently-free
                    // allocation IDs. A planning race therefore fails provisioning
                    // rather than taking an allocation from another server.
                    $server = $this->creation->handle($payload, $plan->deployment);
                } catch (\Throwable $exception) {
                    $archive->update([
                        'status' => LifecycleStatus::RestoreFailed,
                        'last_error' => 'Restore provisioning failed; archive retained.',
                    ]);
                    throw $exception;
                }
                $archive->update([
                    'status' => LifecycleStatus::Restoring,
                    'restored_server_id' => $server->id,
                    'last_error' => null,
                ]);

                return $server;
            });
    }
}
