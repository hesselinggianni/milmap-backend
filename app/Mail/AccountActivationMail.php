<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountActivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $setupUrl;
    public string $planLabel;
    public string $firstName;

    public function __construct(string $setupUrl, string $planLabel, string $firstName = '')
    {
        $this->setupUrl   = $setupUrl;
        $this->planLabel  = $planLabel;
        $this->firstName  = $firstName;
    }

    public function build()
    {
        return $this->subject(__('mail.account_activation.subject'))
                    ->view('emails.account-activation')
                    ->with([
                        'setupUrl'  => $this->setupUrl,
                        'planLabel' => $this->planLabel,
                        'firstName' => $this->firstName,
                    ]);
    }
}
