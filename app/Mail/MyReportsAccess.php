<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MyReportsAccess extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $accessUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Åtkomst till dina rapporter');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.my-reports-access',
            with: [
                'url' => $this->accessUrl,
            ],
        );
    }
}
