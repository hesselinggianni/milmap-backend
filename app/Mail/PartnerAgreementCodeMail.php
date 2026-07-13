<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerAgreementCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public Partner $partner;
    public string $code;
    public string $confirmUrl;

    public function __construct(Partner $partner, string $code)
    {
        $this->partner = $partner;
        $this->code    = $code;
        $this->confirmUrl = rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/')
            . '/overeenkomst?code=' . $code;
    }

    public function build()
    {
        return $this->subject('Bevestig je partnerovereenkomst — code ' . $this->code)
                    ->view('emails.partner-agreement-code');
    }
}
