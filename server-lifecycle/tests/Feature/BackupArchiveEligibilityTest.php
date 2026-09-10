<?php

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Services\Archive\ArchiveEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{Server, LifecyclePolicy, BackupHost} */
function backupEligibilityContext(): array
{
    $server = Server::factory()->create();
    $host = BackupHost::factory()->create(['schema' => 's3']);
    $policy = new LifecyclePolicy(['archive_backup_host_id' => $host->id]);
    $policy->setRelation('archiveBackupHost', $host);

    return [$server, $policy, $host];
}

it('blocks lifecycle archival when an ordinary Pelican backup exists', function (): void {
    [$server, $policy, $host] = backupEligibilityContext();
    Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $host->id]);

    expect(fn () => app(ArchiveEligibilityService::class)->assertEligible($server, $policy))
        ->toThrow(RuntimeException::class, 'existing Pelican backups');
});

it('allows the exact lifecycle backup while rejecting any additional backup', function (): void {
    [$server, $policy, $host] = backupEligibilityContext();
    $lifecycleBackup = Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $host->id]);

    app(ArchiveEligibilityService::class)->assertEligible(
        $server,
        $policy,
        $lifecycleBackup->id,
    );

    $ordinaryBackup = Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $host->id]);

    expect(fn () => app(ArchiveEligibilityService::class)->assertEligible(
        $server,
        $policy,
        $lifecycleBackup->id,
    ))->toThrow(RuntimeException::class, 'existing Pelican backups')
        ->and($ordinaryBackup->fresh())->not->toBeNull();
});
