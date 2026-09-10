<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

use HarbourmasterSam\ServerLifecycle\Enums\WarningPhase;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;

final class LifecyclePolicySnapshotService
{
    /**
     * Build the deliberately small, plaintext historical policy record.
     *
     * Never use Model::toArray() here: a caller may have loaded the BackupHost
     * relation, whose encrypted configuration contains storage credentials.
     *
     * @return array<string, mixed>
     */
    public function build(LifecyclePolicy $policy): array
    {
        $policy->loadMissing('warningRules');

        return [
            'policy_id' => $policy->id,
            'policy_name' => $policy->name,
            'inactivity_minutes' => $policy->inactivity_minutes,
            'running_counts_as_active' => $policy->running_counts_as_active,
            'archive_retention_minutes' => $policy->archive_retention_minutes,
            'final_delivery_mode' => $policy->final_delivery_mode->value,
            'final_delivery_grace_minutes' => $policy->final_delivery_grace_minutes,
            'attachment_max_bytes' => $policy->attachment_max_bytes,
            'delete_warning_rules' => $policy->warningRules
                ->where('phase', WarningPhase::Delete)
                ->map(fn ($rule): array => [
                    'rule_key' => "delete:{$rule->offset_minutes}",
                    'offset_minutes' => (int) $rule->offset_minutes,
                    'database_enabled' => (bool) $rule->database_enabled,
                    'email_enabled' => (bool) $rule->email_enabled,
                ])->values()->all(),
        ];
    }
}
