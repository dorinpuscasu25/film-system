<?php

namespace App\Mail;

use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackupStatusMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $headline,
        public ?string $body,
        public ?BackupRun $run,
        public string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.backup-status',
        );
    }
}
