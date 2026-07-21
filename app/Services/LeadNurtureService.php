<?php

namespace App\Services;

use App\Mail\LeadFinishAccountMail;
use App\Models\Lead;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\MailSend;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Nurture-mail voor leads die de start-funnel begonnen (e-mail ingevuld) maar
 * geen account hebben afgemaakt.
 *
 * Mail 1 ("account afmaken" + 20%-code, 2 dagen geldig) versturen we DIRECT
 * (buiten de campagne-queue, zelfde patroon als de bestaande pricing-exit-
 * couponmail) — omdat 'm een per-lead unieke Stripe-promotiecode nodig heeft
 * die de generieke blok-templates niet kunnen injecteren.
 *
 * Na het versturen leggen we wél een dag-0 mail_sends-rij aan (status=sent,
 * followup_id=0) in de campagne "Lead-nurture: account afmaken" (zie migratie
 * 2026_07_20_190100_seed_lead_nurture_campaign.php). Zo pakt de bestaande
 * follow-up-engine (ProcessMailFollowups, elke minuut) de "waarom MilMap"-mail
 * 4 dagen later vanzelf op — geen nieuwe scheduler-code nodig.
 */
class LeadNurtureService
{
    public const CAMPAIGN_NAME = 'Lead-nurture: account afmaken';

    public function enroll(Lead $lead): bool
    {
        if (! $lead->email) {
            return false;
        }

        // Al geconverteerd (kwam via een andere weg alsnog aan een account) —
        // geen nurture-mail meer nodig.
        if ($lead->converted_at) {
            return false;
        }

        $campaign = MailCampaign::where('name', self::CAMPAIGN_NAME)->first();
        if (! $campaign) {
            Log::warning('[lead-nurture] campagne niet gevonden — is de migratie gedraaid?');
            return false;
        }

        // Idempotent: nooit twee keer de finish-account-mail naar dezelfde lead
        // (unique(campaign_id, followup_id, email) zou dit ook afdwingen, maar
        // we checken vooraf zodat we niet onnodig een Stripe-coupon minten).
        $alreadySent = MailSend::where('campaign_id', $campaign->id)
            ->where('followup_id', 0)
            ->where('email', $lead->email)
            ->exists();
        if ($alreadySent) {
            return false;
        }

        try {
            $result = app(LaunchCouponService::class)->createLeadNurtureCode($lead->email, 2);
        } catch (\Throwable $e) {
            Log::warning('[lead-nurture] coupon aanmaken mislukt: ' . $e->getMessage());
            return false;
        }

        $webAppUrl = rtrim(config('milmap.web_app_url', 'https://app.milmap.nl'), '/');
        $continueUrl = $webAppUrl . '/checkout/pro_monthly?' . http_build_query([
            'email'  => $lead->email,
            'coupon' => $result['code'],
        ]);

        try {
            Mail::to($lead->email)->send(new LeadFinishAccountMail(
                couponCode: $result['code'],
                couponExpiresLabel: $result['expires_at']->locale('nl')->isoFormat('D MMMM'),
                continueUrl: $continueUrl,
            ));
        } catch (\Throwable $e) {
            Log::warning('[lead-nurture] mail versturen mislukt: ' . $e->getMessage());
            return false;
        }

        MailCampaignRecipient::firstOrCreate(
            ['campaign_id' => $campaign->id, 'email' => $lead->email],
            ['lead_id' => $lead->id, 'source' => 'lead'],
        );

        // Bookkeeping-rij: al verzonden via de Mailable hierboven (niet via de
        // job-queue) — deze rij bestaat puur zodat de follow-up-engine de
        // dag-4-mail hierop kan laten volgen.
        MailSend::create([
            'campaign_id'  => $campaign->id,
            'followup_id'  => 0,
            'email'        => $lead->email,
            'lead_id'      => $lead->id,
            'language'     => 'nl',
            'template_key' => 'lead_finish_account',
            'category_id'  => $campaign->category_id,
            'subject'      => 'Maak je MilMap-account af — 20% korting, 2 dagen geldig',
            'status'       => 'sent',
            'token'        => $this->newToken(),
            'position'     => 0,
            'sent_at'      => now(),
        ]);

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
