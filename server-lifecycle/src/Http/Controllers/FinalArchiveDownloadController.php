<?php

namespace HarbourmasterSam\ServerLifecycle\Http\Controllers;

use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Http\RedirectResponse;

class FinalArchiveDownloadController
{
    public function __invoke(ServerArchive $archive, ArchiveStorageInterface $storage): RedirectResponse
    {
        abort_unless($archive->status === LifecycleStatus::PendingDeletion, 410);
        abort_unless($archive->final_delivery_expires_at?->isFuture(), 410);
        abort_unless(filled($archive->object_key) && $storage->exists($archive), 404);

        return redirect()->away($storage->temporaryDownloadUrl($archive, CarbonInterval::minutes(5)));
    }
}
