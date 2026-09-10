<?php

use App\Models\Backup;
use App\Services\Backups\DeleteBackupService;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Archive\TemporaryBackupCleanupService;
use Mockery as M;

it('unlocks and natively deletes only the exact linked pre-adoption backup', function (): void {
    $archive = M::mock(ServerArchive::class)->makePartial();
    $archive->forceFill(['id' => 'archive', 'backup_id' => 10, 'original_server_id' => 20, 'original_backup_uuid' => 'backup-uuid', 'status' => LifecycleStatus::ArchiveCancelled]);
    $backup = M::mock(Backup::class)->makePartial();
    $backup->forceFill(['id' => 10, 'server_id' => 20, 'uuid' => 'backup-uuid']);
    $backup->exists = true;
    $backup->shouldReceive('update')->once()->with(['is_locked' => false]);
    $deletion = M::mock(DeleteBackupService::class);
    $deletion->shouldReceive('handle')->once()->with($backup);

    (new TemporaryBackupCleanupService($deletion))->handle($archive, $backup);
});

it('does not unlock or delete a mismatched or adopted backup', function (?int $archiveBackupId): void {
    $archive = M::mock(ServerArchive::class)->makePartial();
    $archive->forceFill(['id' => 'archive', 'backup_id' => $archiveBackupId, 'original_server_id' => 20, 'original_backup_uuid' => 'expected', 'status' => LifecycleStatus::ArchiveCancelled]);
    $backup = M::mock(Backup::class)->makePartial();
    $backup->forceFill(['id' => 11, 'server_id' => 20, 'uuid' => 'wrong']);
    $backup->exists = true;
    $backup->shouldNotReceive('update');
    $deletion = M::mock(DeleteBackupService::class);
    $deletion->shouldNotReceive('handle');

    (new TemporaryBackupCleanupService($deletion))->handle($archive, $backup);
})->with(['mismatch' => 10, 'adopted archive' => null]);
