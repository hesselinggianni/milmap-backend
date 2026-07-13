<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerApplicationReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Partner $partner;
    public ?string $setupUrl;

    public function __construct(Partner $partner, ?string $setupUrl = null)
    {
        $this->partner  = $partner;
        $this->setupUrl = $setupUrl;
    }

    public function build()
    {
        return $this->subject('Je partneraanmelding bij Milmap is ontvangen')
                    ->view('emails.partner-application-received');
    }
}
