<?php

namespace Darvis\Mfa\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The login mail: a code to type and, next to it, a link that does the same.
 */
class LoginCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $url,
        public readonly int $minutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('mfa::mfa.mail_subject', ['app' => config('app.name')]))
            ->line(__('mfa::mfa.mail_intro'))
            ->line(__('mfa::mfa.mail_code', ['code' => $this->code]));

        if ($this->url !== null) {
            $message->action(__('mfa::mfa.mail_action'), $this->url);
        }

        return $message
            ->line(trans_choice('mfa::mfa.mail_expires', $this->minutes, ['minutes' => $this->minutes]))
            ->line(__('mfa::mfa.mail_ignore'));
    }
}
