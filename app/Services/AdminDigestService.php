<?php

namespace App\Services;

use App\Http\Controllers\AdminBillingController;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Support\GeoIp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cijfers voor de periodieke admin-overzichtsmails (dag/week/maand/kwartaal/
 * jaar, zie SendAdminDigest + AdminDigestMail): wie zich heeft aangemeld,
 * voor welk abonnement, via welke URL en uit welk land — plus de totaalstand.
 *
 * Een periode is altijd de VORIGE volledige kalenderperiode in Amsterdamse tijd
 * ("gisteren", "vorige week", …), dus een mail die om 07:00 uitgaat dekt precies
 * een afgeronde periode en weken/maanden/kwartalen tellen exact op tot het
 * jaar. Grenzen worden naar de app-tijdzone (UTC) omgezet voor de queries.
 */
class AdminDigestService
{
    public const KINDS = ['day', 'week', 'month', 'quarter', 'year'];

    public const TIMEZONE = 'Europe/Amsterdam';

    /** Maximaal aantal regels per lijst in de mail (Gmail knipt berichten boven ±100 KB af); de tellingen zelf zijn altijd volledig. */
    private const LIST_LIMIT = ['day' => 40, 'week' => 20, 'month' => 20, 'quarter' => 20, 'year' => 20];

    /** Plan-sleutels waar de mail een eigen label voor heeft (lang-bestanden mail.php → admin_digest.plans). */
    private const PLAN_KEYS = ['pro_monthly', 'pro_yearly', 'team_monthly', 'team_yearly', 'lifetime'];

    /** Een paar landnamen om de ISO-code leesbaar te maken; onbekende landen blijven de kale code. */
    private const COUNTRIES = [
        'NL' => 'Nederland', 'BE' => 'België', 'DE' => 'Duitsland', 'FR' => 'Frankrijk', 'ES' => 'Spanje',
        'IT' => 'Italië', 'PT' => 'Portugal', 'GB' => 'Verenigd Koninkrijk', 'IE' => 'Ierland', 'US' => 'Verenigde Staten',
        'CA' => 'Canada', 'AU' => 'Australië', 'NZ' => 'Nieuw-Zeeland', 'AT' => 'Oostenrijk', 'CH' => 'Zwitserland',
        'LU' => 'Luxemburg', 'DK' => 'Denemarken', 'SE' => 'Zweden', 'NO' => 'Noorwegen', 'FI' => 'Finland',
        'IS' => 'IJsland', 'PL' => 'Polen', 'CZ' => 'Tsjechië', 'SK' => 'Slowakije', 'HU' => 'Hongarije',
        'RO' => 'Roemenië', 'BG' => 'Bulgarije', 'GR' => 'Griekenland', 'HR' => 'Kroatië', 'SI' => 'Slovenië',
        'RS' => 'Servië', 'LT' => 'Litouwen', 'LV' => 'Letland', 'EE' => 'Estland', 'UA' => 'Oekraïne',
        'TR' => 'Turkije', 'IL' => 'Israël', 'AE' => 'Verenigde Arabische Emiraten', 'ZA' => 'Zuid-Afrika',
        'BR' => 'Brazilië', 'MX' => 'Mexico', 'AR' => 'Argentinië', 'IN' => 'India', 'JP' => 'Japan',
        'KR' => 'Zuid-Korea', 'CN' => 'China', 'SG' => 'Singapore', 'CW' => 'Curaçao', 'SR' => 'Suriname',
        'AW' => 'Aruba', 'SX' => 'Sint Maarten', 'BQ' => 'Caribisch Nederland',
    ];

    /** @var array<string, string>|null Per service-instantie onthouden: één Setting-query per overzicht. */
    private ?array $priceMap = null;

    /**
     * De periode die een overzicht van dit soort dekt, gezien vanaf $now: de
     * voorgaande volledige dag/week(ma–zo)/maand/kwartaal/jaar. `to` is exclusief
     * (= begin van de lopende periode).
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function period(string $kind, ?CarbonInterface $now = null): array
    {
        $now = ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now())->setTimezone(self::TIMEZONE);

        $to = match ($kind) {
            'day'     => $now->startOfDay(),
            'week'    => $now->startOfWeek(CarbonInterface::MONDAY),
            'month'   => $now->startOfMonth(),
            'quarter' => $now->startOfQuarter(),
            'year'    => $now->startOfYear(),
            default   => throw new \InvalidArgumentException("Onbekend overzichtstype: {$kind}"),
        };

        $from = match ($kind) {
            'day'     => $to->subDay(),
            'week'    => $to->subWeek(),
            'month'   => $to->subMonth(),
            'quarter' => $to->subQuarter(),
            'year'    => $to->subYear(),
        };

        return ['from' => $from, 'to' => $to];
    }

    /** Landnaam bij een ISO-2 code ("NL" → "Nederland"); onbekend → de code, leeg → "Onbekend". */
    public static function countryName(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        return self::COUNTRIES[strtoupper($code)] ?? strtoupper($code);
    }

    /**
     * Alles wat de mail nodig heeft voor één periode.
     *
     * @return array<string, mixed>
     */
    public function build(string $kind, ?CarbonInterface $now = null): array
    {
        ['from' => $from, 'to' => $to] = $this->period($kind, $now);
        $prev = $this->period($kind, $from);

        $dbFrom = $this->toDb($from);
        $dbTo   = $this->toDb($to);

        $users = $this->realUsers()
            ->where('created_at', '>=', $dbFrom)->where('created_at', '<', $dbTo)
            ->orderByDesc('created_at')
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at', 'trial_ends_at', 'google_sub', 'apple_sub',
                'signup_source_url', 'signup_referrer', 'signup_country']);

        $leads = $this->openLeads()
            ->where('created_at', '>=', $dbFrom)->where('created_at', '<', $dbTo)
            ->orderByDesc('created_at')
            ->get();

        $newSubs = Subscription::query()->with('user:id,email,first_name,last_name')
            ->where('created_at', '>=', $dbFrom)->where('created_at', '<', $dbTo)
            ->whereNotIn('stripe_status', ['incomplete', 'incomplete_expired'])
            ->orderByDesc('created_at')
            ->get();

        $ended = $this->endedSubscriptions($dbFrom, $dbTo);

        $planOf  = $this->currentPlanKeys($users->pluck('id'));
        $limit   = self::LIST_LIMIT[$kind];
        $priceOf = $this->priceToKey();

        $signupRows = $users->map(fn (User $u) => [
            'email'    => $u->email,
            'name'     => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null,
            'at'       => $this->local($u->created_at),
            'plan'     => $planOf[$u->id] ?? ($u->trial_ends_at && $u->trial_ends_at->isFuture() ? 'trial' : 'free'),
            'method'   => $u->google_sub ? 'google' : ($u->apple_sub ? 'apple' : 'email'),
            'url'      => $u->signup_source_url,
            'referrer' => $u->signup_referrer,
            'country'  => $u->signup_country,
        ]);

        $leadRows = $leads->map(fn (Lead $l) => [
            'email'     => $l->email,
            'at'        => $this->local($l->created_at),
            'source'    => $l->source,
            'url'       => $l->source_url,
            'referrer'  => $l->referrer,
            'country'   => $l->country ?: GeoIp::countryForIp($l->ip_address),
            'step'      => $l->funnel_step,
        ]);

        $subRows = $newSubs->map(fn (Subscription $s) => [
            'email'  => $s->user?->email ?? '—',
            'name'   => $s->user ? (trim(($s->user->first_name ?? '') . ' ' . ($s->user->last_name ?? '')) ?: null) : null,
            'at'     => $this->local($s->created_at),
            'plan'   => $this->planKey($priceOf, $s->stripe_price),
            'status' => $s->stripe_status,
        ]);

        $endedRows = $ended->map(fn (Subscription $s) => [
            'email' => $s->user?->email ?? '—',
            'at'    => $this->local($s->ends_at ?: $s->updated_at),
            'plan'  => $this->planKey($priceOf, $s->stripe_price),
        ]);

        return [
            'kind'   => $kind,
            'from'   => $from,
            'to'     => $to,
            'limit'  => $limit,

            'signups'       => ['total' => $signupRows->count(), 'rows' => $signupRows->take($limit)->values()->all()],
            'leads'         => ['total' => $leadRows->count(),   'rows' => $leadRows->take($limit)->values()->all()],
            'subscriptions' => ['total' => $subRows->count(),    'rows' => $subRows->take($limit)->values()->all()],
            'cancellations' => ['total' => $endedRows->count(),  'rows' => $endedRows->take($limit)->values()->all()],

            'previous' => $this->counts($this->toDb($prev['from']), $dbFrom),

            'breakdown' => [
                'signups_by_plan'    => $signupRows->countBy('plan')->sortDesc()->all(),
                'signups_by_country' => $this->top($signupRows->countBy(fn ($r) => $r['country'] ?: '??')),
                'signups_by_source'  => $this->top($signupRows->countBy(fn ($r) => $this->sourceLabel($r['url']))),
                'leads_by_country'   => $this->top($leadRows->countBy(fn ($r) => $r['country'] ?: '??')),
                'leads_by_source'    => $this->top($leadRows->countBy(fn ($r) => $this->sourceLabel($r['url'], $r['source']))),
            ],

            'trend'  => $this->trend($kind, $from, $to, $users, $leads, $newSubs),
            'totals' => $this->totals(),
        ];
    }

    // ── Totaalstand ────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function totals(): array
    {
        $priceOf = $this->priceToKey();

        $paidByPlan = [];
        $this->activeSubscriptions()
            ->selectRaw('stripe_price, COUNT(*) as c')->groupBy('stripe_price')->get()
            ->each(function ($row) use (&$paidByPlan, $priceOf) {
                $key = $this->planKey($priceOf, $row->stripe_price);
                $paidByPlan[$key] = ($paidByPlan[$key] ?? 0) + (int) $row->c;
            });
        arsort($paidByPlan);

        $payingUsers = (int) $this->activeSubscriptions()->distinct()->count('user_id');

        $users   = $this->realUsers()->whereNull('archived_at');
        $total   = (clone $users)->count();
        $onTrial = (clone $users)->where('trial_ends_at', '>', now())
            ->whereDoesntHave('subscriptions', fn ($q) => $this->scopeActive($q))
            ->count();

        return [
            'users'         => $total,
            'users_on_trial' => $onTrial,
            'users_free'    => max(0, $total - $payingUsers - $onTrial),
            'users_paying'  => $payingUsers,
            'paid_by_plan'  => $paidByPlan,
            'leads'         => $this->openLeads()->count(),
        ];
    }

    // ── Hulpfuncties ───────────────────────────────────────────────

    /** Gebruikers die meetellen: geen demo-accounts. */
    private function realUsers()
    {
        return User::query()->where(fn ($q) => $q->where('is_demo', false)->orWhereNull('is_demo'));
    }

    /**
     * Leads die (nog) géén gebruiker zijn geworden. Wie na het achterlaten van
     * z'n e-mailadres een account kreeg staat al bij de aanmeldingen en telt niet
     * ook nog eens als lead. Naast converted_at (gezet door Lead::markConverted)
     * kijken we naar het e-mailadres zelf: oudere accounts en uitnodigingen
     * zetten dat vlaggetje niet altijd.
     */
    private function openLeads()
    {
        return Lead::query()
            ->whereNull('converted_at')
            ->whereNotIn('email', fn ($q) => $q->select('email')->from('users'));
    }

    /** Lopende betaalde abonnementen (zelfde definitie als User::activeSubscription()). */
    private function activeSubscriptions()
    {
        return $this->scopeActive(Subscription::query());
    }

    private function scopeActive($query)
    {
        return $query->whereIn('stripe_status', ['active', 'trialing'])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** Abonnementen die in [from, to) zijn afgelopen (opgezegd) — zelfde bucketing als het admin-dashboard. */
    private function endedSubscriptions(string $dbFrom, string $dbTo): Collection
    {
        return Subscription::query()->with('user:id,email')
            ->where('stripe_status', 'canceled')
            ->where(function ($q) use ($dbFrom, $dbTo) {
                $q->where(fn ($q2) => $q2->where('ends_at', '>=', $dbFrom)->where('ends_at', '<', $dbTo))
                  ->orWhere(fn ($q2) => $q2->whereNull('ends_at')->where('updated_at', '>=', $dbFrom)->where('updated_at', '<', $dbTo));
            })
            ->get();
    }

    /** Basistellingen van een periode, voor de vergelijking met de vorige. */
    private function counts(string $dbFrom, string $dbTo): array
    {
        return [
            'signups'       => $this->realUsers()->where('created_at', '>=', $dbFrom)->where('created_at', '<', $dbTo)->count(),
            'leads'         => $this->openLeads()->where('created_at', '>=', $dbFrom)->where('created_at', '<', $dbTo)->count(),
            'subscriptions' => Subscription::where('created_at', '>=', $dbFrom)->where('created_at', '<', $dbTo)
                ->whereNotIn('stripe_status', ['incomplete', 'incomplete_expired'])->count(),
            'cancellations' => $this->endedSubscriptions($dbFrom, $dbTo)->count(),
        ];
    }

    /** Stripe price-ID → plan-sleutel (pro_monthly, …) volgens de door de admin ingestelde prijzen. */
    private function priceToKey(): array
    {
        return $this->priceMap ??= collect(AdminBillingController::effectiveMap())
            ->filter()
            ->flip()
            ->all();
    }

    private function planKey(array $priceOf, ?string $price): string
    {
        $key = $price ? ($priceOf[$price] ?? null) : null;

        return in_array($key, self::PLAN_KEYS, true) ? $key : 'paid_other';
    }

    /**
     * Huidig betaald plan per gebruiker (alleen wie een lopend abonnement heeft).
     * Dit is de stand op het moment van versturen — iemand die zich gisteren
     * aanmeldde en vanochtend upgradede staat dus al als betalend.
     *
     * @return array<int, string>
     */
    private function currentPlanKeys(Collection $userIds): array
    {
        $priceOf = $this->priceToKey();
        $out = [];

        foreach ($userIds->chunk(500) as $chunk) {
            $this->activeSubscriptions()->whereIn('user_id', $chunk->all())->get(['user_id', 'stripe_price'])
                ->each(function ($s) use (&$out, $priceOf) {
                    $out[$s->user_id] ??= $this->planKey($priceOf, $s->stripe_price);
                });
        }

        return $out;
    }

    /**
     * Kort herkomstlabel om op te groeperen: host + pad zonder query ("milmap.nl/app").
     * App-aanmeldingen ("ios-app /pad") blijven als platform staan; zonder URL valt
     * het terug op $fallback (bij leads het source-label uit het formulier).
     */
    private function sourceLabel(?string $url, ?string $fallback = null): string
    {
        if ($url) {
            if (preg_match('~^(ios|android)-app~i', $url, $m)) {
                return strtolower($m[1]) . '-app';
            }
            $short = self::shortUrl($url, withQuery: false);
            if ($short !== '') {
                return $short;
            }
        }

        return $fallback ?: '';
    }

    /** "https://www.milmap.nl/app/?utm=x#y" → "milmap.nl/app" (met of zonder query). */
    public static function shortUrl(?string $url, bool $withQuery = true): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $url);
        $url = preg_replace('~^www\.~i', '', $url);
        $url = explode('#', $url, 2)[0];
        if (! $withQuery) {
            $url = explode('?', $url, 2)[0];
        }

        return rtrim($url, '/?');
    }

    /**
     * Top-N van een telling, de rest als "overig".
     *
     * @return array{rows: array<int, array{label: string, count: int}>, other: int}
     */
    private function top(Collection $counts, int $limit = 8): array
    {
        $sorted = $counts->sortDesc();
        $rows = $sorted->take($limit)->map(fn ($count, $label) => ['label' => (string) $label, 'count' => (int) $count])->values()->all();

        return ['rows' => $rows, 'other' => (int) $sorted->slice($limit)->sum()];
    }

    /**
     * Verloop binnen de periode: per dag (week), per kalenderweek (maand) of per
     * maand (kwartaal/jaar). Een dagoverzicht heeft geen verloop.
     *
     * @return array<int, array{from: CarbonImmutable, to: CarbonImmutable, signups: int, leads: int, subscriptions: int}>
     */
    private function trend(string $kind, CarbonImmutable $from, CarbonImmutable $to, Collection $users, Collection $leads, Collection $subs): array
    {
        if ($kind === 'day') {
            return [];
        }

        $rows = [];
        for ($cursor = $from; $cursor < $to;) {
            $next = match ($kind) {
                'week'  => $cursor->addDay(),
                'month' => $cursor->startOfWeek(CarbonInterface::MONDAY)->addWeek(),
                default => $cursor->addMonth(),
            };
            if ($next > $to) {
                $next = $to;
            }

            $inBucket = fn ($c) => $c->filter(fn ($m) => $this->local($m->created_at) >= $cursor && $this->local($m->created_at) < $next)->count();

            $rows[] = [
                'from'          => $cursor,
                'to'            => $next,
                'signups'       => $inBucket($users),
                'leads'         => $inBucket($leads),
                'subscriptions' => $inBucket($subs),
            ];
            $cursor = $next;
        }

        return $rows;
    }

    /** DB-timestamps staan in de app-tijdzone; toon ze in Amsterdamse tijd. */
    private function local($timestamp): CarbonImmutable
    {
        return CarbonImmutable::instance($timestamp)->setTimezone(self::TIMEZONE);
    }

    private function toDb(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(config('app.timezone'))->toDateTimeString();
    }
}
