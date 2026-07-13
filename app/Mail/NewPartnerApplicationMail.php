<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewPartnerApplicationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Partner $partner;

    public function __construct(Partner $partner)
    {
        $this->partner = $partner;
    }

    public function build()
    {
        return $this->subject('Nieuwe partneraanmelding — ' . ($this->partner->company_name ?: $this->partner->user->email))
                    ->view('emails.new-partner-application');
    }
}
