<?php

namespace App\Notifications\PdfJob;

use App\Models\PdfJob;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PdfJobCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public PdfJob $job,
        public string $downloadUrl
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your TenthLine document is ready')
            ->view('emails.pdf-jobs.completed', [
                'name' => $notifiable->name,
                'filename' => $this->job->filename,
                'downloadUrl' => $this->downloadUrl,
                'expiresInHours' => 24,
            ]);
    }
}
