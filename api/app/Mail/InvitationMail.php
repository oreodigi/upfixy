<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public function __construct(public string $link, public string $workspace, public string $role, public string $expires) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to {$this->workspace} on Upfixy");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation');
    }
}
