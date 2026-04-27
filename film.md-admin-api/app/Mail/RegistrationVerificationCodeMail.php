<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public string $expiresAtLabel,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your filmoteca.md account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.registration-verification-code',
            with: [
                'userName' => $this->user->name,
                'code' => $this->code,
                'expiresAtLabel' => $this->expiresAtLabel,
            ],
        );
    }
}
