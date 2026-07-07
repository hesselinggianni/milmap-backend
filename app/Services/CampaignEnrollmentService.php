<?php

namespace App\Services;

use App\Jobs\SendCampaignMessage;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\MailSend;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Plaatst een nieuwe gebruiker automatisch in elke campagne die als
 * onboarding-funnel is gemarkeerd (auto_enroll_on_signup = true).
 *
 * Werking: we maken direct de dag-0-mail (mail_sends met followup_id = 0) aan en
 * zetten 'm in de wachtrij. De bestaande follow-up-engine (ProcessMailFollowups,
 * elke minuut) stuurt daarna vanzelf de vervolgmails op +N dagen, gerekend vanaf
 * de sent_at van déze dag-0-mail — dus de funnel-klok start per gebruiker op het
 * moment van registratie. We loggen de gebruiker ook als recipient zodat de admin
 * ziet wie in de funnel zit.
 *
 * Best-effort: faalt nooit hard — een probleem hier mag de registratie niet breken.
 */
class CampaignEnrollmentService
{
    /**
     * Enroll a freshly registered user into all auto-enroll funnels.
     *
     * @return int aantal campagnes waarin de gebruiker nu is geplaatst
     */
    public function enrollNewUser(User $user): int
    {
        if (! $user->email) {
            return 0;
        }

        $campaigns = MailCampaign::where('auto_enroll_on_signup', true)->get();
        $enrolled = 0;

        foreach ($campaigns as $campaign) {
            try {
                if ($this->enroll($campaign, $user)) {
                    $enrolled++;
                }
            } catch (\Throwable $e) {
                Log::warning('[funnel] enroll mislukt', [
                    'campaign' => $campaign->id,
                    'user'     => $user->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $enrolled;
    }

    /**
     * Plaats één gebruiker in één campagne (idempotent op de dag-0-mail).
     */
    public function enroll(MailCampaign $campaign, User $user): bool
    {
        if (! MailTemplateRegistry::exists($campaign->template_key)) {
            Log::warning('[funnel] campagne heeft onbekende template — overgeslagen', [
                'campaign' => $campaign->id,
                'template' => $campaign->template_key,
            ]);
            return false;
        }

        $language = $user->language ?: ($campaign->default_locale ?: 'nl');
        $resolved = MailTemplateRegistry::resolve(
            $campaign->template_key,
            $language,
            $campaign->default_locale ?: 'nl'
        );

        // Recipient-rij voor het admin-overzicht (idempotent per e-mail).
        MailCampaignRecipient::firstOrCreate(
            ['campaign_id' => $campaign->id, 'email' => $user->email],
            [
                'user_id' => $user->id,
                'name'    => $user->full_name,
                'source'  => 'auto-signup',
            ],
        );

        // Dag-0-mail — unique(campaign_id, followup_id, email) maakt dit idempotent,
        // zodat dubbele enrollment nooit een tweede welkomstmail oplevert.
        $send = MailSend::firstOrCreate(
            ['campaign_id' => $campaign->id, 'followup_id' => 0, 'email' => $user->email],
            [
                'user_id'      => $user->id,
                'name'         => $user->full_name,
                'language'     => $resolved['locale'] ?? $language,
                'template_key' => $campaign->template_key,
                'category_id'  => $campaign->category_id,
                'subject'      => $resolved['subject'] ?? null,
                'status'       => 'queued',
                'token'        => $this->newToken(),
                'position'     => 0,
            ],
        );

        if (! $send->wasRecentlyCreated) {
            return false; // al ingeschreven
        }

        SendCampaignMessage::dispatch($send->id);

        return true;
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(32);
        } while (MailSend::where('token', $token)->exists());

        return $token;
    }
}
