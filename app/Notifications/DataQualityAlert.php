<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataQualityAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $severity,
        public string $source,
        public string $alertMessage,
        public ?array $details = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->severity === 'error'
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[Data Quality Alert] {$this->source}: {$this->severity}")
            ->line($this->alertMessage)
            ->line("Source: {$this->source}")
            ->line("Severity: {$this->severity}")
            ->action('View Dashboard', url('/admin/data-quality'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'severity' => $this->severity,
            'source' => $this->source,
            'message' => $this->alertMessage,
            'details' => $this->details,
        ];
    }
}
