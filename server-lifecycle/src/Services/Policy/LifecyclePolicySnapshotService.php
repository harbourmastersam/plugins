<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;

final class LifecyclePolicySnapshotService
{
    /**
     * Build the deliberately small, plaintext historical policy record.
     *
     * Never use Model::toArray() here: a caller may have loaded the BackupHost
     * relation, whose encrypted configuration contains storage credentials.
     *
     * @return array<string, bool|int|string|null>
     */
    public function build(LifecyclePolicy $policy): array
    {
        return [
            'policy_id' => $policy->id,
            'policy_name' => $policy->name,
            'inactivity_minutes' => $policy->inactivity_minutes,
            'running_counts_as_active' => $policy->running_counts_as_active,
            'archive_retention_minutes' => $policy->archive_retention_minutes,
            'final_delivery_mode' => $policy->final_delivery_mode->value,
            'final_delivery_grace_minutes' => $policy->final_delivery_grace_minutes,
            'attachment_max_bytes' => $policy->attachment_max_bytes,
        ];
    }
}
