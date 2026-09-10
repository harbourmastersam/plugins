<?php

namespace HarbourmasterSam\ServerLifecycle\Jobs;

use HarbourmasterSam\ServerLifecycle\Enums\FinalDeliveryMode;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Notifications\FinalArchiveDeliveryNotification;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

class DeliverFinalArchiveJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $archiveId) {}

    public function uniqueId(): string
    {
        return $this->archiveId;
    }

    public function handle(ArchiveStorageInterface $storage): void
    {
        $archive = ServerArchive::query()->findOrFail($this->archiveId);
        if ($archive->status !== LifecycleStatus::PendingDeletion || $archive->final_delivery_sent_at) {
            return;
        }

        $mode = FinalDeliveryMode::from(data_get($archive->policy_snapshot, 'final_delivery_mode', 'none'));
        if ($mode === FinalDeliveryMode::None) {
            $archive->update(['final_delivery_sent_at' => now(), 'last_error' => null]);

            return;
        }
        if (! $archive->owner) {
            throw new RuntimeException('Final delivery requires an available archive owner.');
        }

        $limit = (int) data_get($archive->policy_snapshot, 'attachment_max_bytes', config('server-lifecycle.attachment_max_bytes'));
        $attach = in_array($mode, [FinalDeliveryMode::AttachmentIfSmall, FinalDeliveryMode::AttachmentIfSmallElseLink], true)
            && $archive->bytes !== null
            && $archive->bytes <= $limit;
        $link = in_array($mode, [FinalDeliveryMode::DownloadLink, FinalDeliveryMode::AttachmentIfSmallElseLink], true)
            || ($mode === FinalDeliveryMode::AttachmentIfSmall && ! $attach);
        $url = $link ? URL::temporarySignedRoute('server-lifecycle.archives.final-download', $archive->final_delivery_expires_at, ['archive' => $archive->id]) : null;
        $path = null;

        try {
            if ($attach) {
                $path = tempnam(storage_path('app'), 'lifecycle-archive-');
                if ($path === false) {
                    throw new RuntimeException('Unable to create a secure temporary archive file.');
                }
                $storage->downloadToPath($archive, $path);
            }
            $archive->owner->notifyNow(new FinalArchiveDeliveryNotification($archive, $url, $path));
            $archive->update(['final_delivery_sent_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            $archive->update(['last_error' => 'Final archive delivery failed and will be retried.']);
            report($exception);
            throw $exception;
        } finally {
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }
}
