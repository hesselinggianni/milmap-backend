<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Marketing-update die aankondigt dat je in MilMap routes kunt plannen:
 * importeren uit TopoGPS en exporteren naar Garmin. Gebruikt de MilMap-huisstijl
 * (emails.layout) met het MilMap-logo.
 */
class RoutePlanningUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $appUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuw in Milmap: plan je route — van TopoGPS tot Garmin',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marketing.route-planning',
            with: [
                'appUrl' => $this->appUrl,
            ],
        );
    }
}
