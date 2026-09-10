<?php

namespace HarbourmasterSam\ServerLifecycle\Listeners;

use App\Events\Server\Installed;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Restore\ContinueRestoreService;

class ContinueRestoreAfterInstall
{
    public function __construct(private ContinueRestoreService $restore) {}
    public function handle(Installed $event): void
    {
        $server = $event->server; $archive = ServerArchive::query()->where('restored_server_id', $server->id)->where('status', LifecycleStatus::Restoring)->first(); if (! $archive) return;
        if (property_exists($event, 'successful') && ! $event->successful) { $archive->update(['status' => LifecycleStatus::RestoreFailed, 'last_error' => 'Server installation failed; archive retained.']); return; }
        $this->restore->handle($archive, $server);
    }
}
