<?php

namespace App\Console\Commands;

use App\Jobs\SendCampaignMessage;
use App\Models\Lead;
use App\Models\MailFollowup;
use App\Models\MailSend;
use App\Models\User;
use App\Services\MailTemplateRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verwerkt follow-up-regels: voor elke actieve regel zoekt 'ie de "vorige" mails in de
 * keten waarvoor de voorwaarde (received/read/not_read/not_received) geldt én de
 * vertraging (delay_days) verstreken is, en zet daar een follow-up-mail voor in de queue.
 *
 * Idempotent: een prev krijgt nooit twee keer dezelfde follow-up (gecheckt via een
 * not-exists + de unique(campaign_id,followup_id,email)-index). Draait met
 * ->withoutOverlapping() zodat gelijktijdige runs elkaar niet dubbel triggeren.
 *
 * NB: 'read'/'not_read' leunen op de open-tracking-pixel (best-effort); 'not_received'
 * betekent hier 'failed|skipped_optout' want het log-/SMTP-transport levert geen bounce.
 */
class ProcessMailFollowups extends Command
{
    protected $signature = 'mail:process-followups';
    protected $description = 'Verstuur follow-up-mails op basis van hun voorwaarde + vertraging.';

    /**
     * Templates die alleen zin hebben voor gebruikers zonder actief betaald abonnement
     * (upsell/korting). Een betalende gebruiker slaat deze stap over — gemarkeerd als
     * 'skipped_subscribed' zodat de keten niet blijft herproberen.
     */
    private const PAID_ONLY_SKIP_TEMPLATES = ['onboarding_upgrade', 'onboarding_discount'];

    public function handle(): int
    {
        $created = 0;
        $followups = MailFollowup::where('active', true)->with('campaign')->orderBy('position')->get();

        foreach ($followups as $f) {
            if (! $f->campaign) {
                continue;
            }
            $created += $this->processFollowup($f);
        }

        $this->info("Follow-ups verwerkt: {$created} nieuwe mail(s) in de wachtrij.");

        return self::SUCCESS;
    }

    private function processFollowup(MailFollowup $f): int
    {
        $campaignId = $f->campaign_id;
        // De "vorige" mail in de tak = de send van de OUDER-follow-up, of de hoofdmail
        // (followup_id = 0) als deze follow-up direct op de campagne hangt. De vertraging
        // + conditie tellen dus vanaf het moment dat die ouder-mail verzonden is.
        $prevFollowupId = $f->parent_followup_id ?: 0;
        $cutoff = now()->subDays($f->delay_days);

        $q = MailSend::where('campaign_id', $campaignId)->where('followup_id', $prevFollowupId);

        switch ($f->condition) {
            case 'received':
                $q->where('status', 'sent')->whereNotNull('sent_at')->where('sent_at', '<=', $cutoff);
                break;
            case 'read':
                $q->where('status', 'sent')->whereNotNull('opened_at')->whereNotNull('sent_at')->where('sent_at', '<=', $cutoff);
                break;
            case 'not_read':
                $q->where('status', 'sent')->whereNull('opened_at')->whereNotNull('sent_at')->where('sent_at', '<=', $cutoff);
                break;
            case 'not_received':
                // Alleen écht niet-afgeleverd (mislukt/bounced). Een afmelding
                // (skipped_optout) is een bewuste keuze, géén afleverfout — die mag
                // nooit een follow-up triggeren.
                $q->whereIn('status', ['failed', 'bounced'])
                    ->where(fn ($w) => $w->where('sent_at', '<=', $cutoff)->orWhere('updated_at', '<=', $cutoff));
                break;
            default:
                return 0;
        }

        // Sluit prevs uit die al een follow-up van deze regel hebben (idempotent).
        $q->whereNotExists(function ($sub) use ($campaignId, $f) {
            $sub->select(DB::raw(1))->from('mail_sends as c')
                ->whereColumn('c.email', 'mail_sends.email')
                ->where('c.campaign_id', $campaignId)
                ->where('c.followup_id', $f->id);
        });

        $count = 0;
        $q->orderBy('id')->chunkById(200, function ($prevs) use ($f, &$count) {
            foreach ($prevs as $prev) {
                if ($this->createChild($f, $prev)) {
                    $count++;
                }
            }
        });

        return $count;
    }

    private function createChild(MailFollowup $f, MailSend $prev): bool
    {
        $campaign = $f->campaign;
        $language = $prev->language ?: ($campaign->default_locale ?: 'nl');
        $resolved = MailTemplateRegistry::resolve($f->template_key, $language, $campaign->default_locale ?: 'nl');
        if (! $resolved) {
            $this->warn("Onbekende follow-up-template: {$f->template_key}");
            return false;
        }

        // Abonnement-check: een gebruiker die al betaalt hoeft geen upsell/korting-mail.
        $skipForSubscriber = $prev->user_id
            && in_array($f->template_key, self::PAID_ONLY_SKIP_TEMPLATES, true)
            && (User::find($prev->user_id)?->subscribed() ?? false);

        // Lead-check: kwam deze prev-mail van een lead (geen user_id) en heeft
        // die lead ondertussen een account gekregen (Lead::markConverted, bv.
        // via registratie of guest-checkout)? Dan hoeft de vervolgmail
        // ("account afmaken" / "waarom MilMap") niet meer.
        $skipForConverted = $prev->lead_id
            && (Lead::find($prev->lead_id)?->converted_at !== null);

        try {
            $child = MailSend::firstOrCreate(
                ['campaign_id' => $f->campaign_id, 'followup_id' => $f->id, 'email' => $prev->email],
                [
                    'user_id'      => $prev->user_id,
                    'lead_id'      => $prev->lead_id,
                    'name'         => $prev->name,
                    'language'     => $resolved['locale'] ?? $language,
                    'template_key' => $f->template_key,
                    'category_id'  => $f->category_id ?: $campaign->category_id,
                    'subject'      => $f->subject_override ?: ($resolved['subject'] ?? null),
                    'status'       => $skipForSubscriber ? 'skipped_subscribed' : ($skipForConverted ? 'skipped_converted' : 'queued'),
                    'token'        => $this->newToken(),
                    'position'     => $f->position,
                ],
            );
        } catch (\Throwable $e) {
            // Gelijktijdige run heeft 'm net gemaakt → niets te doen.
            return false;
        }

        if (! $child->wasRecentlyCreated) {
            return false;
        }

        if ($skipForSubscriber || $skipForConverted) {
            return false; // wél geregistreerd (idempotent), maar niet verzonden/geteld
        }

        SendCampaignMessage::dispatch($child->id);

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
