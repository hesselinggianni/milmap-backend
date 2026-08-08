<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewUserRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $sourceUrl;
    public $referrer;
    /** Antwoorden uit de start-funnel, indien deze bezoeker die doorliep. */
    public $useCase;
    public $interests;
    public $funnelStep;

    public function __construct($user, $sourceUrl = null, $referrer = null)
    {
        $this->user      = $user;
        $this->sourceUrl = $sourceUrl;
        $this->referrer  = $referrer;

        // De funnel-antwoorden hangen aan de LEAD, niet aan de user: ze zijn
        // gegeven vóórdat het account bestond. Op e-mailadres erbij zoeken,
        // best-effort — een registratie zonder voorafgaande funnel (directe
        // /register, uitnodiging) heeft ze simpelweg niet.
        try {
            $lead = \App\Models\Lead::where('email', mb_strtolower(trim((string) ($user->email ?? ''))))->first();
            $this->useCase    = $lead?->use_case;
            $this->interests  = $lead?->interests ?: [];
            $this->funnelStep = $lead?->funnel_step;
        } catch (\Throwable $e) {
            $this->useCase    = null;
            $this->interests  = [];
            $this->funnelStep = null;
        }
    }

    public function build()
    {
        return $this
            ->subject('Nieuwe gebruiker geregistreerd')
            ->view('emails.new-user-registered');
    }
}