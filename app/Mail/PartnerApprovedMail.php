<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Partner $partner;

    public function __construct(Partner $partner)
    {
        $this->partner = $partner;
    }

    public function build()
    {
        return $this->subject(__('mail.partner_approved.subject'))
                    ->view('emails.partner-approved');
    }
}
