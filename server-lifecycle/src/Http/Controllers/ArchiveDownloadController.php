<?php

namespace HarbourmasterSam\ServerLifecycle\Http\Controllers;

use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArchiveDownloadController
{
    public function __invoke(ServerArchive $archive, ArchiveStorageInterface $storage): RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user && ($user->root_admin || ((int) $archive->owner_id === (int) $user->id && config('server-lifecycle.users_may_download'))), 403);
        if (in_array($archive->status, [LifecycleStatus::Deleted, LifecycleStatus::PendingDeletion], true) && (! $archive->final_delivery_expires_at || $archive->final_delivery_expires_at->isPast())) throw new HttpException(410);
        abort_unless($storage->exists($archive), 404);
        return redirect()->away($storage->temporaryDownloadUrl($archive, CarbonInterval::seconds((int) config('server-lifecycle.download_ttl_seconds', 300))));
    }
}
