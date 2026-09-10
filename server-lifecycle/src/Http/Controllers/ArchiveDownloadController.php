<?php

namespace HarbourmasterSam\ServerLifecycle\Http\Controllers;

use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Http\RedirectResponse;

class ArchiveDownloadController
{
    public function __invoke(ServerArchive $archive, ArchiveStorageInterface $storage): RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user && ($user->root_admin || ((int) $archive->owner_id === (int) $user->id && config('server-lifecycle.users_may_download'))), 403);
        $downloadable = in_array($archive->status, [
            LifecycleStatus::Archived,
            LifecycleStatus::DeletionWarning,
            LifecycleStatus::Restored,
            LifecycleStatus::RestoreFailed,
            LifecycleStatus::Restoring,
        ], true) || ($archive->status === LifecycleStatus::PendingDeletion
            && $archive->final_delivery_expires_at?->isFuture());

        abort_unless($downloadable, 410);
        abort_unless(filled($archive->object_key) && $storage->exists($archive), 404);
        return redirect()->away($storage->temporaryDownloadUrl($archive, CarbonInterval::seconds((int) config('server-lifecycle.download_ttl_seconds', 300))));
    }
}
