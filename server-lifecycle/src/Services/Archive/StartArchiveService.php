<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Policy\LifecyclePolicySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StartArchiveService
{
    public function __construct(
        private ArchiveEligibilityService $eligibility,
        private ServerManifestService $manifests,
        private LifecycleBackupService $backups,
        private LifecyclePolicySnapshotService $snapshots,
    ) {}

    public function handle(Server $server, LifecyclePolicy $policy): ServerArchive
    {
        return Cache::lock("server-lifecycle:archive:$server->id", 300)
            ->block(5, fn (): ServerArchive => $this->startWhileLocked($server, $policy));
    }

    private function startWhileLocked(Server $server, LifecyclePolicy $policy): ServerArchive
    {
        $server = $server->fresh();
        $policy->loadMissing('archiveBackupHost');
        $this->eligibility->assertEligible($server, $policy);
        $manifest = $this->manifests->capture($server);

        [$archive, $state] = DB::transaction(function () use ($server, $policy, $manifest): array {
            $state = ServerLifecycleState::query()
                ->where('server_id', $server->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($state->status, [LifecycleStatus::Active, LifecycleStatus::Warning], true)) {
                throw new RuntimeException('A lifecycle operation is already in progress or requires explicit recovery.');
            }

            $archive = ServerArchive::query()->create([
                'owner_id' => $server->owner_id,
                'original_server_id' => $server->id,
                'original_server_uuid' => $server->uuid,
                'original_uuid_short' => $server->uuid_short,
                'server_name' => $server->name,
                'description' => $server->description,
                'original_node' => ['id' => $server->node_id],
                'original_allocations' => $manifest['allocations'],
                'backup_host_id' => $policy->archive_backup_host_id,
                'status' => LifecycleStatus::Archiving,
                'archive_started_at' => now(),
                'activity_snapshot_at' => $state->last_activity_at,
                'manifest' => $manifest,
                'policy_snapshot' => $this->snapshots->build($policy),
            ]);

            $state->update([
                'status' => LifecycleStatus::Archiving,
                'current_archive_id' => $archive->id,
                'last_error' => null,
            ]);

            return [$archive, $state];
        });

        try {
            $backup = $this->backups->initiate($server, $policy->archiveBackupHost);
            $archive->update([
                'backup_id' => $backup->id,
                'original_backup_uuid' => $backup->uuid,
                'object_key' => "$server->uuid/$backup->uuid.tar.gz",
            ]);

            return $archive;
        } catch (Throwable $exception) {
            $archive->update([
                'status' => LifecycleStatus::ArchiveFailed,
                'last_error' => 'Unable to initiate final backup; live server retained.',
            ]);
            $state->update([
                'status' => LifecycleStatus::ArchiveFailed,
                'current_archive_id' => null,
                'last_error' => 'Unable to initiate final backup; live server retained.',
            ]);

            throw $exception;
        }
    }
}
