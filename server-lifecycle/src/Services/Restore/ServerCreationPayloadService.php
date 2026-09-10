<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use RuntimeException;

class ServerCreationPayloadService
{
    /** @return array<string, mixed> */
    public function build(ServerArchive $archive, RestoreDeploymentPlan $plan, ?int $ownerId = null): array
    {
        $manifest = $archive->manifest;
        if (! $manifest) {
            throw new RuntimeException('Encrypted restore manifest is unavailable.');
        }

        return [
            'name' => $manifest['name'],
            'description' => $manifest['description'] ?? '',
            'owner_id' => $ownerId ?? $archive->owner_id,
            'egg_id' => $manifest['egg_id'],
            'image' => $manifest['image'],
            'startup' => $manifest['startup'],
            'environment' => $manifest['environment'],
            'cpu' => $manifest['cpu'],
            'memory' => $manifest['memory'],
            'swap' => $manifest['swap'],
            'disk' => $manifest['disk'],
            'io' => $manifest['io'],
            'threads' => $manifest['threads'],
            'oom_killer' => $manifest['oom_killer'],
            'database_limit' => $manifest['database_limit'],
            'allocation_limit' => $manifest['allocation_limit'],
            'backup_limit' => $manifest['backup_limit'],
            'allocation_id' => $plan->primaryAllocationId,
            'allocation_additional' => $plan->additionalAllocationIds,
            // Installation prepares Wings' server container. Starting is prohibited
            // until the archive restore callback succeeds.
            'skip_scripts' => false,
            'start_on_completion' => false,
        ];
    }
}
