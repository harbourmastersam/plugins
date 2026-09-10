<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Extensions\BackupAdapter\BackupAdapterService;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use Illuminate\Support\Str;

class LifecycleBackupService
{
    public function __construct(private BackupAdapterService $adapters) {}

    public function create(Server $server, BackupHost $host): Backup
    {
        return Backup::query()->create([
            'server_id' => $server->id,
            'backup_host_id' => $host->id,
            'uuid' => Str::uuid()->toString(),
            'name' => 'Server Lifecycle final archive',
            'ignored_files' => [],
            'is_locked' => true,
            'is_scheduled' => true,
            'is_successful' => false,
        ]);
    }

    public function initiate(Backup $backup): void
    {
        $backup->loadMissing('backupHost');
        $this->adapters->get($backup->backupHost->schema)->createBackup($backup);
    }
}
