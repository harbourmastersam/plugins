<?php

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Services\Archive\ArchiveEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backupEligibilityPolicy(): LifecyclePolicy
{
    $host = new BackupHost();
    $host->forceFill(['schema' => 's3']);
    $policy = new LifecyclePolicy();
    $policy->setRelation('archiveBackupHost', $host);

    return $policy;
}

it('blocks lifecycle archival when an ordinary Pelican backup exists', function (): void {
    $server = Server::factory()->create();
    Backup::factory()->create(['server_id' => $server->id]);

    expect(fn () => app(ArchiveEligibilityService::class)->assertEligible($server, backupEligibilityPolicy()))
        ->toThrow(RuntimeException::class, 'existing Pelican backups');
});

it('allows the exact lifecycle backup while rejecting any additional backup', function (): void {
    $server = Server::factory()->create();
    $lifecycleBackup = Backup::factory()->create(['server_id' => $server->id]);

    app(ArchiveEligibilityService::class)->assertEligible(
        $server,
        backupEligibilityPolicy(),
        $lifecycleBackup->id,
    );

    $ordinaryBackup = Backup::factory()->create(['server_id' => $server->id]);

    expect(fn () => app(ArchiveEligibilityService::class)->assertEligible(
        $server,
        backupEligibilityPolicy(),
        $lifecycleBackup->id,
    ))->toThrow(RuntimeException::class, 'existing Pelican backups')
        ->and($ordinaryBackup->fresh())->not->toBeNull();
});
