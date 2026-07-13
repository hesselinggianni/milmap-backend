<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerPayoutMail extends Mailable
{
    use Queueable, SerializesModels;

    public Partner $partner;
    public float $amount;
    public int $commissionCount;

    public function __construct(Partner $partner, float $amount, int $commissionCount)
    {
        $this->partner         = $partner;
        $this->amount          = $amount;
        $this->commissionCount = $commissionCount;
    }

    public function build()
    {
        return $this->subject('Milmap-partneruitbetaling: €' . number_format($this->amount, 2, ',', '.'))
                    ->view('emails.partner-payout');
    }
}
