<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Sales-signalen → taken.
 *
 * Scant de gebruikers-, abonnement- en lead-data op concrete, actiegerichte
 * verkoop-signalen (aflopende trials, ongeverifieerde accounts, churn-risico,
 * niet-benaderde leads, enz.) en zet elk signaal om in een to-do in de bestaande
 * takenlijst. Deterministisch en data-gestuurd — geen AI, geen giswerk.
 *
 * Elk signaal heeft een stabiele `dedupe_key`, zodat er per signaaltype één
 * levende taak bestaat: bestaat er al een open taak, dan werken we de tekst bij
 * met verse cijfers; is die afgevinkt, dan maakt een volgende run 'm opnieuw aan
 * zolang het signaal blijft gelden.
 */
class SalesTodoService
{
    /** Marker in de todo zodat de sales-taken herkenbaar/filterbaar zijn. */
    public const REPO = 'sales';
    public const MODE = 'signal';

    /**
     * Bereken alle actieve signalen (zonder op te slaan). Handig voor een
     * preview in de UI en als basis voor generate().
     *
     * @return array<int, array{key:string,title:string,description:string,count:int}>
     */
    public function signals(): array
    {
        $now = now();
        $out = [];

        // Sluit gebruikers uit die een lopend betaald abonnement hebben.
        $notPayingSub = fn ($q) => $q->where('stripe_status', 'active')
            ->where(fn ($s) => $s->whereNull('ends_at')->orWhere('ends_at', '>', now()));

        // ── 1. Proefperiodes die binnen 2 dagen aflopen (upgrade-kans) ──────
        $expiring = User::whereNull('archived_at')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays(2)])
            ->whereDoesntHave('subscriptions', $notPayingSub)
            ->orderBy('trial_ends_at')
            ->get(['email', 'trial_ends_at']);
        if ($expiring->count() > 0) {
            $out[] = [
                'key'         => 'sales:trials-expiring',
                'title'       => "📈 {$expiring->count()} proefperiode(s) lopen binnen 2 dagen af — stuur een upgrade-herinnering",
                'description' => "Deze gebruikers verliezen binnenkort hun premium-toegang en hebben nog geen abonnement. Een gerichte reminder (in-app of e-mail) met de voordelen van Pro/Team kan ze nu converteren.\n\n"
                    . $this->emailList($expiring->pluck('email')),
                'count'       => $expiring->count(),
            ];
        }

        // ── 2. Proefperiodes afgelopen (laatste 7 dagen) zonder upgrade ─────
        $lapsed = User::whereNull('archived_at')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now->copy()->subDays(7), $now])
            ->whereDoesntHave('subscriptions', $notPayingSub)
            ->orderByDesc('trial_ends_at')
            ->get(['email', 'trial_ends_at']);
        if ($lapsed->count() > 0) {
            $out[] = [
                'key'         => 'sales:trials-lapsed',
                'title'       => "📈 {$lapsed->count()} proefperiode(s) verlopen zonder upgrade — win-back",
                'description' => "Deze gebruikers hebben de afgelopen week hun trial laten aflopen zonder te upgraden. Een win-back-actie (korting, persoonlijke mail, korte enquête waarom niet) kan een deel terughalen.\n\n"
                    . $this->emailList($lapsed->pluck('email')),
                'count'       => $lapsed->count(),
            ];
        }

        // ── 3. Nieuwe accounts nog niet geverifieerd (>24u, laatste 14 dagen) ─
        $unverified = User::whereNull('archived_at')
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $now->copy()->subHours(User::VERIFICATION_GRACE_HOURS))
            ->where('created_at', '>=', $now->copy()->subDays(14))
            ->orderByDesc('created_at')
            ->get(['email', 'created_at']);
        if ($unverified->count() > 0) {
            $out[] = [
                'key'         => 'sales:unverified',
                'title'       => "📧 {$unverified->count()} account(s) nog niet geverifieerd — stuur re-verificatie",
                'description' => "Deze accounts zijn ouder dan 24 uur maar hebben hun e-mail nog niet bevestigd — ze zitten achter de verificatie-wall en gaan verloren als ze niets doen. Verstuur een re-verificatie-mail of ruim spam op.\n\n"
                    . $this->emailList($unverified->pluck('email')),
                'count'       => $unverified->count(),
            ];
        }

        // ── 4. Betalende klanten al >14 dagen niet actief (churn-risico) ────
        $coldPayers = User::whereNull('archived_at')
            ->whereHas('subscriptions', $notPayingSub)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $now->copy()->subDays(14))
            ->orderBy('last_seen_at')
            ->get(['email', 'last_seen_at']);
        if ($coldPayers->count() > 0) {
            $out[] = [
                'key'         => 'sales:cold-payers',
                'title'       => "⚠️ {$coldPayers->count()} betalende klant(en) al >14 dagen niet actief — churn-risico",
                'description' => "Betalende klanten die al twee weken niet zijn ingelogd, zeggen vaker op. Neem proactief contact op (tips, nieuwe features, vraag of alles werkt) om verloop te voorkomen.\n\n"
                    . $this->emailList($coldPayers->pluck('email')),
                'count'       => $coldPayers->count(),
            ];
        }

        // ── 5. Opzeggingen-piek deze maand t.o.v. vorige maand ──────────────
        $thisMonth = $this->canceledCount($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $prevMonth = $this->canceledCount($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());
        if ($thisMonth > $prevMonth && $thisMonth >= 2) {
            $out[] = [
                'key'         => 'sales:churn-spike',
                'title'       => "📉 Opzeggingen gestegen: {$thisMonth} deze maand vs {$prevMonth} vorige maand — onderzoek de reden",
                'description' => "Het aantal opzeggingen ligt deze maand hoger dan vorige maand. Analyseer wie er opzegde en waarom (recente wijziging, prijs, bug?) en bepaal een tegenmaatregel.",
                'count'       => $thisMonth,
            ];
        }

        // ── 6. Leads nog niet benaderd ──────────────────────────────────────
        $leads = Lead::whereNull('notified_at')->count();
        if ($leads > 0) {
            $out[] = [
                'key'         => 'sales:leads-unfollowed',
                'title'       => "📨 {$leads} lead(s) nog niet benaderd — volg op",
                'description' => "Er staan {$leads} verzamelde leads die nog geen bericht hebben gehad. Stuur ze de 'we zijn live'-mail of een persoonlijke uitnodiging om te starten.",
                'count'       => $leads,
            ];
        }

        // ── 7. Aanmeldingen deze week lager dan vorige week ─────────────────
        $thisWeek = User::whereBetween('created_at', [$now->copy()->startOfWeek(), $now])->count();
        $lastWeekStart = $now->copy()->subWeek()->startOfWeek();
        $lastWeekEnd   = $now->copy()->subWeek()->endOfWeek();
        $lastWeek = User::whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])->count();
        if ($lastWeek >= 3 && $thisWeek < $lastWeek) {
            $out[] = [
                'key'         => 'sales:signups-down',
                'title'       => "📉 Aanmeldingen dalen: {$thisWeek} deze week vs {$lastWeek} vorige week — marketing-push overwegen",
                'description' => "De instroom van nieuwe gebruikers ligt lager dan vorige week. Overweeg een extra marketing-actie (social post, campagne-mail, ads) of controleer of de aanmeldflow ergens hapert.",
                'count'       => $thisWeek,
            ];
        }

        // ── 8. Actieve gratis-gebruikers met veel content (upsell-kans) ─────
        $powerFree = User::whereNull('archived_at')
            ->whereDoesntHave('subscriptions', $notPayingSub)
            ->where(fn ($q) => $q->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<', $now)) // niet (meer) op trial
            ->withCount('maps')
            ->having('maps_count', '>=', 3)
            ->orderByDesc('maps_count')
            ->limit(25)
            ->get(['email']);
        if ($powerFree->count() > 0) {
            $out[] = [
                'key'         => 'sales:upsell-power-free',
                'title'       => "🚀 {$powerFree->count()} actieve gratis-gebruiker(s) met veel content — upgrade-kans",
                'description' => "Deze gratis gebruikers maken al volop kaarten aan maar hebben geen abonnement. Ze halen duidelijk waarde uit MilMap — een gerichte upgrade-prompt (bv. bij de kaartlimiet) of persoonlijke mail kan ze naar Pro/Team tillen.\n\n"
                    . $this->emailList($powerFree->pluck('email')),
                'count'       => $powerFree->count(),
            ];
        }

        return $out;
    }

    /**
     * Genereer taken uit de actieve signalen. Bestaat er al een OPEN taak met
     * dezelfde dedupe_key, dan werken we die bij met verse cijfers; anders maken
     * we 'm aan.
     *
     * @return array{created:int, updated:int, tasks: array<int,array<string,mixed>>}
     */
    public function generate(): array
    {
        $created = 0;
        $updated = 0;
        $tasks   = [];

        foreach ($this->signals() as $sig) {
            $open = Todo::where('dedupe_key', $sig['key'])
                ->whereIn('status', ['backlog', 'voorbereiding', 'wachtrij', 'bezig', 'review', 'feedback'])
                ->first();

            if ($open) {
                $open->update([
                    'title'       => $sig['title'],
                    'description' => $sig['description'],
                ]);
                $updated++;
                $tasks[] = $open->toApiArray();
            } else {
                $todo = Todo::create([
                    'title'             => $sig['title'],
                    'description'       => $sig['description'],
                    'repo'              => self::REPO,
                    'mode'              => self::MODE,
                    'status'            => 'backlog',
                    'source'            => 'admin',
                    'dedupe_key'        => $sig['key'],
                    'created_by'        => null,
                    'status_changed_at' => now(),
                ]);
                $created++;
                $tasks[] = $todo->toApiArray();
            }
        }

        return ['created' => $created, 'updated' => $updated, 'tasks' => $tasks];
    }

    /** Aantal opzeggingen (canceled) in een periode, op einddatum of laatste wijziging. */
    private function canceledCount(Carbon $from, Carbon $to): int
    {
        return Subscription::where('stripe_status', 'canceled')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('ends_at', [$from, $to])
                  ->orWhere(function ($q2) use ($from, $to) {
                      $q2->whereNull('ends_at')->whereBetween('updated_at', [$from, $to]);
                  });
            })
            ->count();
    }

    /** Toon max 8 voorbeeld-e-mailadressen als leesbare lijst. */
    private function emailList($emails): string
    {
        $list = collect($emails)->filter()->take(8);
        $rest = collect($emails)->filter()->count() - $list->count();
        $text = 'Voorbeelden: ' . $list->implode(', ');
        if ($rest > 0) {
            $text .= " … (+{$rest} meer)";
        }
        return $text;
    }
}
