<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Din områdesrapport är klar');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.report-ready',
            with: [
                'url' => url("/reports/{$this->report->uuid}"),
                'address' => $this->report->address,
                'score' => $this->report->score,
            ],
        );
    }
}
