<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Bevestiging dat een account door de beheerder is gesloten.
 *
 * Gaat uit op het moment dat de gebruiker in de admin wordt verwijderd: het
 * account is weg, een lopend abonnement is opgezegd en wat er nog van de
 * gebruiker bewaard blijft, blijft alleen bewaard omdat de wet dat van ons
 * vraagt (facturen en betaalgegevens, fiscale bewaarplicht).
 *
 * De taal komt uit de mail-locale (lang/{en,nl}/mail.php): Engels is de
 * standaard, Nederlands de uitzondering. Omdat de gebruiker op het moment van
 * verzenden al verwijderd is, zet AdminController de locale expliciet met
 * ->locale() in plaats van via HasLocalePreference.
 */
class AccountClosedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public bool $hadSubscription = false,
    ) {
    }

    public function build()
    {
        return $this->subject(__('mail.account_closed.subject'))
            ->view('emails.account-closed')
            ->with([
                'name'            => $this->name,
                'hadSubscription' => $this->hadSubscription,
            ]);
    }
}
