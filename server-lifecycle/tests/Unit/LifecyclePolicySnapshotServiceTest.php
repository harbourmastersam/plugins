<?php

use App\Models\BackupHost;
use HarbourmasterSam\ServerLifecycle\Enums\FinalDeliveryMode;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Services\Policy\LifecyclePolicySnapshotService;
use HarbourmasterSam\ServerLifecycle\Models\LifecycleWarningRule;
use HarbourmasterSam\ServerLifecycle\Enums\WarningPhase;
use Illuminate\Database\Eloquent\Collection;

it('builds a credential-free snapshot even when the backup host is loaded', function (): void {
    $policy = new LifecyclePolicy();
    $policy->forceFill([
        'id' => 42,
        'name' => 'Conservative',
        'inactivity_minutes' => 43200,
        'running_counts_as_active' => true,
        'archive_retention_minutes' => 259200,
        'final_delivery_mode' => FinalDeliveryMode::DownloadLink,
        'final_delivery_grace_minutes' => 10080,
        'attachment_max_bytes' => 20971520,
    ]);
    $host = new BackupHost();
    $host->forceFill([
        'schema' => 's3',
        'configuration' => [
            'key' => 'AKIA_TEST_SECRET',
            'secret' => 'never-serialize-me',
            'token' => 'session-token',
        ],
    ]);
    $policy->setRelation('archiveBackupHost', $host);
    $rule = new LifecycleWarningRule();
    $rule->forceFill(['phase' => WarningPhase::Delete, 'offset_minutes' => 10080, 'database_enabled' => true, 'email_enabled' => false]);
    $policy->setRelation('warningRules', new Collection([$rule]));

    $snapshot = (new LifecyclePolicySnapshotService())->build($policy);
    $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);

    expect($snapshot)->toHaveKeys([
        'policy_id',
        'policy_name',
        'inactivity_minutes',
        'running_counts_as_active',
        'archive_retention_minutes',
        'final_delivery_mode',
        'final_delivery_grace_minutes',
        'attachment_max_bytes',
        'delete_warning_rules',
    ])->not->toHaveKeys(['archiveBackupHost', 'archive_backup_host', 'configuration'])
        ->and($snapshot['delete_warning_rules'])->toBe([[
            'rule_key' => 'delete:10080',
            'offset_minutes' => 10080,
            'database_enabled' => true,
            'email_enabled' => false,
        ]])
        ->and($serialized)->not->toContain('AKIA_TEST_SECRET', 'never-serialize-me', 'session-token');
});
