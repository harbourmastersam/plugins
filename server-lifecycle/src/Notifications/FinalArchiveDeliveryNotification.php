<?php

namespace HarbourmasterSam\ServerLifecycle\Notifications;

use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FinalArchiveDeliveryNotification extends Notification
{
    public function __construct(
        private ServerArchive $archive,
        private ?string $url,
        private ?string $attachmentPath,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject(__('server-lifecycle::strings.notifications.final_title'))
            ->line(__('server-lifecycle::strings.notifications.final_body', ['server' => $this->archive->server_name]));

        if ($this->url) {
            $mail->action(__('server-lifecycle::strings.notifications.download'), $this->url);
        }
        if ($this->attachmentPath) {
            $mail->attach($this->attachmentPath, ['as' => str($this->archive->server_name)->slug().'.tar.gz']);
        }

        return $mail;
    }
}
