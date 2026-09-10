<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Services\Servers\ServerCreationService;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class StartRestoreService
{
    public function __construct(private ArchiveStorageInterface $storage, private ServerCreationService $creation) {}
    public function handle(ServerArchive $archive, ?int $ownerId = null): object
    {
        return Cache::lock("server-lifecycle:restore:$archive->id", 300)->block(5, function () use ($archive, $ownerId): object {
            $archive->refresh();
            if ($archive->status !== LifecycleStatus::Archived && $archive->status !== LifecycleStatus::RestoreFailed) throw new RuntimeException('Archive cannot currently be restored.');
            if (! $this->storage->exists($archive)) throw new RuntimeException('Archive object is unavailable.');
            $manifest = $archive->manifest; if (! $manifest) throw new RuntimeException('Encrypted restore manifest is unavailable.');
            $data = $manifest; $data['owner_id'] = $ownerId ?? $archive->owner_id; $data['start_on_completion'] = false; $data['skip_scripts'] = false;
            // Native creation performs deployment/allocation validation. Original IDs are preferences, never inserted directly.
            $server = $this->creation->handle($data);
            $archive->update(['status' => LifecycleStatus::Restoring, 'restored_server_id' => $server->id, 'last_error' => null]);
            return $server;
        });
    }
}
