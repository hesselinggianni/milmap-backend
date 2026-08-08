<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Download-mail met App Store- / Google Play-knoppen, een app-frame en een
 * unieke Stripe-promotiecode (20% op het jaarabonnement). Clean Apple-vibe in
 * de MilMap-kleuren — zie resources/views/emails/app-download.blade.php.
 */
class AppDownloadMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $couponCode,
        public ?string $couponExpiresLabel = null,
        public string $appStoreUrl = 'https://milmap.nl/app',
        public string $playStoreUrl = 'https://milmap.nl/app',
        public string $webAppUrl = 'https://app.milmap.nl',
        public ?string $recipientName = null,
    ) {}

    public function build()
    {
        return $this->subject('Je MilMap-app staat klaar — plus 20% korting 🎉')
                    ->view('emails.app-download');
    }
}
