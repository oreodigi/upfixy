<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** ALR-001: one plain-text email per recipient; no other recipient is ever named. */
class IncidentMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $kind,      // incident_opened | incident_resolved
        public string $host,
        public string $title,
        public string $meaning,
        public string $when,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->kind === 'incident_opened' ? 'Problem' : 'Resolved';
        return new Envelope(subject: "{$verb}: {$this->host} — {$this->title}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.incident');
    }
}
