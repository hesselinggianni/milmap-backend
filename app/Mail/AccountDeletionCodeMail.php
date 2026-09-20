<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountDeletionCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public int $minutes = 15)
    {
    }

    public function build()
    {
        return $this
            ->subject(__('mail.account_deletion_code.subject'))
            ->view('emails.account-deletion-code', [
                'code'    => $this->code,
                'minutes' => $this->minutes,
            ]);
    }
}
