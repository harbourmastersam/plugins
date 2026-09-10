<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use RuntimeException;

class ArchiveEligibilityService
{
    public function assertEligible(Server $server, LifecyclePolicy $policy): void
    {
        if ($server->databases()->exists()) throw new RuntimeException(__('server-lifecycle::strings.errors.databases_block_archive'));
        foreach (['subusers', 'schedules', 'mounts'] as $relation) {
            if (method_exists($server, $relation) && $server->{$relation}()->exists()) throw new RuntimeException(__('server-lifecycle::strings.errors.unsupported_metadata', ['relation' => $relation]));
        }
        if ($server->getAttribute('status') !== null || $server->getAttribute('suspended') || $server->getAttribute('is_transferring')) throw new RuntimeException(__('server-lifecycle::strings.errors.conflicting_state'));
        // retrieveStatus throws if Wings cannot be contacted: intentionally fail closed.
        $status = $server->retrieveStatus();
        if (! $status->isOffline()) throw new RuntimeException(__('server-lifecycle::strings.errors.must_be_offline'));
        if ($policy->archiveBackupHost->getAttribute('type') !== 's3') throw new RuntimeException(__('server-lifecycle::strings.errors.s3_only'));
    }
}
