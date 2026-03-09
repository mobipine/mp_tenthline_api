<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $expiresInMinutes,
        public ?string $recipientName = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->recipientName;
        if ($name === null && method_exists($notifiable, 'getAttribute')) {
            $name = $notifiable->getAttribute('name');
        }

        return (new MailMessage)
            ->subject('Your LegalLine login code')
            ->view('emails.auth.login-otp', [
                'name' => $name,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
