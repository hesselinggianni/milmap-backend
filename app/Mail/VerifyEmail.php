<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Verificatiemail voor nieuwe (niet-betalende) accounts. Bevat een signed link
 * waarmee de gebruiker zijn e-mailadres bevestigt. Wordt verstuurd bij
 * registratie en bij "opnieuw versturen" vanaf de verificatie-wall.
 */
class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $verificationUrl;

    public function __construct(User $user, string $verificationUrl)
    {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
    }

    public function build()
    {
        return $this
            ->subject('Bevestig je e-mailadres — Milmap')
            ->view('emails.verify-email')
            ->with([
                'firstName'       => $this->user->first_name,
                'verificationUrl' => $this->verificationUrl,
            ]);
    }
}
