<?php

namespace HarbourmasterSam\ServerLifecycle\Jobs;

use App\Models\Server;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use HarbourmasterSam\ServerLifecycle\Models\LifecycleNotificationDelivery;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Notifications\LifecycleWarningNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverLifecycleNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = LifecycleNotificationDelivery::query()->findOrFail($this->deliveryId);
        if ($delivery->delivered_at) {
            return;
        }

        [$user, $title, $message, $url] = $this->content($delivery);
        if (! $user) {
            $delivery->update(['last_error' => 'The archive owner is unavailable.']);

            return;
        }

        try {
            if ($delivery->channel === 'database') {
                FilamentNotification::make()
                    ->title($title)
                    ->body($message)
                    ->warning()
                    ->sendToDatabase($user);
            } else {
                $user->notifyNow(new LifecycleWarningNotification($title, $message, $url));
            }
            $delivery->update(['delivered_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            $delivery->update(['last_error' => 'Notification delivery failed and will be retried.']);
            report($exception);
            throw $exception;
        }
    }

    private function content(LifecycleNotificationDelivery $delivery): array
    {
        if ($delivery->archive_id) {
            $archive = ServerArchive::query()->find($delivery->archive_id);
            $user = $archive?->owner;
            $url = $archive ? route('server-lifecycle.archives.download', ['archive' => $archive->id]) : null;

            return [$user, __('server-lifecycle::strings.notifications.delete_title'), __('server-lifecycle::strings.notifications.delete_body', ['server' => $archive?->server_name, 'date' => $delivery->target_at]), $url];
        }

        $server = Server::query()->find($delivery->server_id);
        $user = $server ? User::query()->find($server->owner_id) : null;

        return [$user, __('server-lifecycle::strings.notifications.archive_title'), __('server-lifecycle::strings.notifications.archive_body', ['server' => $server?->name, 'date' => $delivery->target_at]), null];
    }
}
