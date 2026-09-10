<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use App\Models\WebhookConfiguration;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use RuntimeException;

class ArchiveEligibilityService
{
    public function assertEligible(Server $server, LifecyclePolicy $policy, ?int $allowedBackupId = null): void
    {
        if ($server->databases()->exists()) throw new RuntimeException(__('server-lifecycle::strings.errors.databases_block_archive'));
        $backups = $server->backups();
        if ($allowedBackupId !== null) {
            $backups->where('id', '!=', $allowedBackupId);
        }
        if ($backups->exists()) {
            throw new RuntimeException(__('server-lifecycle::strings.errors.backups_block_archive'));
        }
        foreach (['subusers', 'schedules', 'mounts'] as $relation) {
            if (method_exists($server, $relation) && $server->{$relation}()->exists()) throw new RuntimeException(__('server-lifecycle::strings.errors.unsupported_metadata', ['relation' => $relation]));
        }
        if (WebhookConfiguration::query()
            ->where('scope', 'server')
            ->where('server_id', $server->id)
            ->exists()) {
            throw new RuntimeException(__('server-lifecycle::strings.errors.unsupported_metadata', ['relation' => 'webhook configurations']));
        }
        if (! $server->isInstalled() || $server->isInConflictState() || $server->transfer()->exists()) {
            throw new RuntimeException(__('server-lifecycle::strings.errors.conflicting_state'));
        }

        if ($policy->archiveBackupHost->schema !== 's3') {
            throw new RuntimeException(__('server-lifecycle::strings.errors.s3_only'));
        }
    }
}
