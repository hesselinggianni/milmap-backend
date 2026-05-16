<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(string $resetUrl)
    {
        $this->resetUrl = $resetUrl;
    }

    public function build()
    {
        return $this->subject('Wachtwoord resetten — MilMap')
                    ->view('emails.reset-password')
                    ->with(['resetUrl' => $this->resetUrl]);
    }
}
