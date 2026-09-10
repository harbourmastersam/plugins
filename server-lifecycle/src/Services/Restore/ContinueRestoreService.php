<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Enums\ServerState;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonBackupRepository;
use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Str;

class ContinueRestoreService
{
    public function __construct(private ArchiveStorageInterface $storage, private DaemonBackupRepository $daemon) {}
    public function handle(ServerArchive $archive, Server $server): void
    {
        $backup = Backup::query()->create(['server_id' => $server->id, 'backup_host_id' => $archive->backup_host_id, 'uuid' => Str::uuid()->toString(), 'name' => 'Server Lifecycle restore bookkeeping', 'disk' => 's3', 'is_locked' => true, 'is_successful' => false]);
        $archive->update(['restore_backup_id' => $backup->id]);
        $server->update(['status' => ServerState::RestoringBackup]);
        try { $this->daemon->setServer($server)->restore($backup, $this->storage->temporaryDownloadUrl($archive, CarbonInterval::minutes(5)), true); }
        catch (\Throwable $e) { $archive->update(['status' => \HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus::RestoreFailed, 'last_error' => 'Wings restore request failed; archive retained.']); throw $e; }
    }
}
