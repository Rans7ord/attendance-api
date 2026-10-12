<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetCodeMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $code,
        public int $minutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your password reset code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset-code');
    }
}