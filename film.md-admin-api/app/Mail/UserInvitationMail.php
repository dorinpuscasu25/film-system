<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $appName,
        public ?string $inviteeName,
        public string $inviterName,
        public string $acceptUrl,
        public ?CarbonInterface $expiresAt,
        public array $roleNames = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->appName} invitation",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
        );
    }
}
