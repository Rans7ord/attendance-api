<?php

namespace App\Mail;

use App\Models\Invite;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InviteMail extends Mailable
{
    public function __construct(public Invite $invite, public string $companyName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invite');
    }
}