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

    public function initiate(Server $server, BackupHost $host): Backup
    {
        $backup = Backup::query()->create([
            'server_id' => $server->id, 'backup_host_id' => $host->id, 'uuid' => Str::uuid()->toString(),
            'name' => 'Server Lifecycle final archive', 'ignored_files' => [], 'disk' => 's3', 'is_locked' => true, 'is_successful' => false,
        ]);
        try {
            $this->adapters->get($host->schema)->createBackup($backup);
        } catch (\Throwable $exception) {
            $backup->delete();
            throw $exception;
        }
        return $backup;
    }
}
