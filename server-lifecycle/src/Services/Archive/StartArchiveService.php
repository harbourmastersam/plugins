<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Support\Facades\Cache;

class StartArchiveService
{
    public function __construct(private ArchiveEligibilityService $eligibility, private ServerManifestService $manifests, private LifecycleBackupService $backups) {}
    public function handle(Server $server, LifecyclePolicy $policy): ServerArchive
    {
        return Cache::lock("server-lifecycle:archive:$server->id", 300)->block(5, function () use ($server, $policy): ServerArchive {
            $this->eligibility->assertEligible($server->fresh(), $policy->load('archiveBackupHost'));
            $manifest = $this->manifests->capture($server);
            $archive = ServerArchive::query()->create(['owner_id' => $server->owner_id, 'original_server_id' => $server->id, 'original_server_uuid' => $server->uuid, 'original_uuid_short' => $server->uuidShort, 'server_name' => $server->name, 'description' => $server->description, 'original_node' => ['id' => $server->node_id], 'original_allocations' => $manifest['allocations'], 'backup_host_id' => $policy->archive_backup_host_id, 'object_key' => null, 'status' => LifecycleStatus::Archiving, 'manifest' => $manifest, 'policy_snapshot' => $policy->toArray()]);
            try {
                $backup = $this->backups->initiate($server, $policy->archiveBackupHost);
                $archive->update(['backup_id' => $backup->id, 'original_backup_uuid' => $backup->uuid, 'object_key' => "$server->uuid/$backup->uuid.tar.gz"]);
                return $archive;
            } catch (\Throwable $exception) { $archive->update(['status' => LifecycleStatus::Failed, 'last_error' => 'Unable to initiate final backup.']); throw $exception; }
        });
    }
}
