<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Retry-mail voor leads die de start-funnel begonnen (e-mail ingevuld) maar
 * geen account hebben afgemaakt: nodigt uit om af te maken, met een unieke
 * 20%-kortingscode die kort geldig is. Verstuurd direct (buiten de
 * campagne-queue) door LeadNurtureService — zelfde patroon als AppDownloadMail.
 */
class LeadFinishAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $couponCode,
        public string $couponExpiresLabel,
        public string $continueUrl,
    ) {}

    public function build()
    {
        return $this->subject('Maak je MilMap-account af — 20% korting, 2 dagen geldig')
                    ->view('emails.lead-finish-account');
    }
}
