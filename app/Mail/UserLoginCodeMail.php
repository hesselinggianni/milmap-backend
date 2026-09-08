<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Inlogcode voor gebruikers zonder wachtwoord (of die 'm zijn vergeten).
 * Zelfde opzet als AdminLoginCodeMail, andere doelgroep/tekst.
 */
class UserLoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Je inlogcode — Milmap',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-login-code',
            with: [
                'code' => $this->code,
                'expiryMinutes' => 15,
            ],
        );
    }
}
