<?php

use App\Models\MailCampaign;
use App\Models\MailCategory;
use App\Models\MailFollowup;
use Illuminate\Database\Migrations\Migration;

/**
 * Bouwt de onboarding-funnel (Project 8) kant-en-klaar op: één campagne met de
 * dag-0-welkomstmail als hoofdtemplate + vijf follow-ups op dag 1/3/5/7/14. De
 * admin hoeft 'm alleen nog aan te zetten (auto_enroll_on_signup) of handmatig te
 * versturen. Idempotent: draait 'ie nog eens, dan wordt er niets gedupliceerd.
 *
 * auto_enroll staat bewust op FALSE — zet 'm pas aan in de admin nadat de
 * templates gecontroleerd zijn én MAIL_MAILER op smtp staat (anders landen mails
 * in het log). De funnel-klok loopt per gebruiker vanaf hun dag-0-mail.
 */
return new class extends Migration {
    public function up(): void
    {
        $category = MailCategory::where('key', 'productinfo')->first();

        $campaign = MailCampaign::firstOrCreate(
            ['template_key' => 'onboarding_welcome', 'name' => 'Onboarding-funnel (na registratie)'],
            [
                'description'           => 'Automatische e-mailfunnel voor nieuwe gebruikers: welkom → eerste kaart → geavanceerd → klantcase → upgrade → korting.',
                'category_id'           => $category?->id,
                'default_locale'        => 'nl',
                'status'                => 'draft',
                'auto_enroll_on_signup' => false,
            ],
        );

        // Follow-ups alleen aanmaken als de campagne er nog geen heeft (idempotent).
        if ($campaign->followups()->exists()) {
            return;
        }

        $steps = [
            ['delay' => 1,  'template' => 'onboarding_first_map'],
            ['delay' => 3,  'template' => 'onboarding_advanced'],
            ['delay' => 5,  'template' => 'onboarding_case'],
            ['delay' => 7,  'template' => 'onboarding_upgrade'],
            ['delay' => 14, 'template' => 'onboarding_discount'],
        ];

        foreach ($steps as $i => $step) {
            MailFollowup::create([
                'campaign_id'        => $campaign->id,
                'parent_followup_id' => null,        // hangt aan de hoofdmail (dag 0)
                'template_key'       => $step['template'],
                'category_id'        => $category?->id,
                'delay_days'         => $step['delay'],
                'condition'          => 'received',  // stuur N dagen nadat de dag-0-mail is verzonden
                'position'           => $i + 1,
                'active'             => true,
            ]);
        }
    }

    public function down(): void
    {
        $campaign = MailCampaign::where('template_key', 'onboarding_welcome')
            ->where('name', 'Onboarding-funnel (na registratie)')
            ->first();

        if ($campaign) {
            // Alleen verwijderen als er nog niets is verstuurd (geen log wissen).
            if (! $campaign->sends()->exists()) {
                $campaign->followups()->delete();
                $campaign->delete();
            }
        }
    }
};
