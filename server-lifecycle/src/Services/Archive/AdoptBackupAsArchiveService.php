<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Backup;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Removes only Pelican's database association. Never call DeleteBackupService here: it destroys the adopted object. */
class AdoptBackupAsArchiveService
{
    public function handle(ServerArchive $archive, Backup $backup): void
    {
        DB::transaction(function () use ($archive, $backup): void {
            if (! $backup->completed_at || ! $backup->is_successful || $backup->bytes < 1 || blank($backup->checksum)) throw new RuntimeException('An incomplete backup cannot be adopted.');
            $archive->forceFill(['backup_id' => null, 'original_backup_uuid' => $backup->uuid, 'bytes' => $backup->bytes, 'checksum' => $backup->checksum])->save();
            Backup::query()->whereKey($backup->getKey())->delete();
        });
    }
}
