<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain-text security emails: password reset link, password changed, MFA locked. */
class SecurityNoticeMail extends Mailable
{
    public function __construct(public string $kind, public array $data = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'reset' => 'Reset your Upfixy password',
            'changed' => 'Your Upfixy password was changed',
            'mfa_locked' => 'Upfixy sign-in codes locked after failed attempts',
        });
    }

    public function content(): Content
    {
        return new Content(text: 'mail.security-' . str_replace('_', '-', $this->kind));
    }
}
