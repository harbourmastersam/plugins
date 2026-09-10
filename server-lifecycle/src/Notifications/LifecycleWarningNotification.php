<?php

namespace HarbourmasterSam\ServerLifecycle\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LifecycleWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $title,
        private string $message,
        private ?string $actionUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())->subject($this->title)->line($this->message);
        if ($this->actionUrl) {
            $mail->action(__('server-lifecycle::strings.notifications.view'), $this->actionUrl);
        }

        return $mail;
    }
}
