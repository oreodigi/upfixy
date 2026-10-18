<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyEmailMail extends Mailable
{
    public function __construct(public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email for Upfixy');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.verify-email');
    }
}
