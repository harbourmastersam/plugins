<?php

namespace HarbourmasterSam\ServerLifecycle\Jobs;

use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Archive\PermanentDeleteArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteArchiveJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(public string $archiveId) {}
    public function uniqueId(): string { return $this->archiveId; }
    public function handle(PermanentDeleteArchiveService $service): void { $service->handle(ServerArchive::query()->findOrFail($this->archiveId)); }
}
