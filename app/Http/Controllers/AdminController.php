<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Map;
use App\Models\RouteMap;
use App\Models\Mission;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordMail;
use App\Mail\AccountClosedMail;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\MessageCreated;

class AdminController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            // Alleen tellingen — bewust geen kaarttitels/datums inladen.
            $baseUsers = User::select('id', 'first_name', 'last_name', 'email', 'is_admin', 'is_demo', 'created_at', 'email_verified_at', 'last_seen_at', 'trial_ends_at')
                ->withCount('maps')
                ->get();

            // "Online" = laatst gezien in de afgelopen 5 minuten.
            $onlineThreshold = now()->subMinutes(5);

            // Actieve abonnementen per user (voor de plan-kolom) — één query,
            // geen N+1. Spiegelt User::activeSubscription() (active/trialing en
            // niet-verlopen), gegroepeerd op user_id.
            $activeSubs = \App\Models\Subscription::whereIn('stripe_status', ['active', 'trialing'])
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('user_id');

            // Per-gebruiker tellingen van routekaarten en missies in twee
            // groeps-queries (geen N+1, en zonder de rijen/titels te laden).
            $routeMapCounts = RouteMap::selectRaw('owner_id, COUNT(*) as c')
                ->groupBy('owner_id')
                ->pluck('c', 'owner_id');
            $missionCounts = Mission::selectRaw('owner_id, COUNT(*) as c')
                ->groupBy('owner_id')
                ->pluck('c', 'owner_id');

            $mobileDevices = \App\Models\ConnectDevice::connected()
                ->whereIn('platform', ['ios', 'android'])
                ->orderByDesc('last_seen_at')
                ->get(['user_id', 'installation_id', 'platform', 'name', 'model', 'os_version', 'app_version', 'last_seen_at'])
                ->groupBy('user_id');

            $users = $baseUsers->map(function ($user) use ($routeMapCounts, $missionCounts, $onlineThreshold, $activeSubs, $mobileDevices) {
                $lastSeen = $user->last_seen_at
                    ? \Illuminate\Support\Carbon::parse($user->last_seen_at)
                    : null;

                // Plan bepalen: betaald abonnement > app-proef > gratis starter.
                $sub = optional($activeSubs->get($user->id))->first();
                $planInterval = null;
                if ($sub) {
                    $tier = User::tierForPrice($sub->stripe_price);        // pro | team | starter
                    $plan = $tier === 'starter' ? 'pro' : $tier;          // actieve sub = minstens betaald
                    $key = User::planKeyForPrice($sub->stripe_price);      // *_monthly | *_yearly
                    if ($key) $planInterval = str_ends_with($key, 'yearly') ? 'yearly' : 'monthly';
                } elseif ($user->trial_ends_at && \Illuminate\Support\Carbon::parse($user->trial_ends_at)->isFuture()) {
                    $plan = 'trial';
                } else {
                    $plan = 'starter';
                }

                return [
                    'id'               => $user->id,
                    'name'             => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'email'            => $user->email,
                    'is_admin'         => (bool) $user->is_admin,
                    'is_demo'          => (bool) $user->is_demo,
                    'mobile_devices'   => $mobileDevices->get($user->id, collect())->map(fn ($device) => [
                        'platform' => $device->platform,
                        'installation_id' => $device->installation_id,
                        'name' => $device->name,
                        'model' => \App\Support\DeviceInfo::modelName($device->model),
                        'os_version' => $device->os_version,
                        'app_version' => $device->app_version,
                        'last_seen_at' => $device->last_seen_at,
                    ])->values()->all(),
                    'maps_count'       => (int) $user->maps_count,
                    'route_maps_count' => (int) ($routeMapCounts[$user->id] ?? 0),
                    'missions_count'   => (int) ($missionCounts[$user->id] ?? 0),
                    'created_at'       => $user->created_at,
                    'email_verified'   => (bool) $user->email_verified_at,
                    'plan'             => $plan,
                    'plan_interval'    => $planInterval,
                    'last_seen_at'     => $lastSeen,
                    'is_online'        => $lastSeen ? $lastSeen->gt($onlineThreshold) : false,
                ];
            })->values();

            // Groei deze week / maand: aantal nieuw aangemaakte items sinds het
            // begin van de huidige (ISO-)week en de huidige maand. Louter tellingen
            // op created_at — geen extra rijen ingeladen.
            $weekStart  = now()->startOfWeek();
            $monthStart = now()->startOfMonth();
            $delta = fn ($query) => [
                'week'  => (clone $query)->where('created_at', '>=', $weekStart)->count(),
                'month' => (clone $query)->where('created_at', '>=', $monthStart)->count(),
            ];

            return response()->json([
                'users' => $users,
                'stats' => [
                    // Demo-accounts (eigen test-accounts) tellen niet mee in de
                    // totalen/groei; ze blijven wél zichtbaar in de lijst.
                    'total_users'     => $baseUsers->where('is_demo', false)->count(),
                    'total_maps'      => Map::count(),
                    'total_routemaps' => RouteMap::count(),
                    'total_missions'  => Mission::count(),
                    'deltas' => [
                        'users'     => $delta(User::where('is_demo', false)),
                        'admins'    => $delta(User::where('is_admin', true)),
                        'maps'      => $delta(Map::query()),
                        'routemaps' => $delta(RouteMap::query()),
                        'missions'  => $delta(Mission::query()),
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij ophalen statistieken',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Omzet-overzicht voor het admin-dashboard.
     *
     * Twee blokken:
     *  1. `income` — WERKELIJK geïnde omzet uit Stripe per lopende kalender-
     *     periode (vandaag, deze week/maand/kwartaal/half jaar/jaar, telkens
     *     tot-nu-toe). We halen de charges sinds 1 januari één keer op en
     *     bucketten ze zelf — netto (min terugbetalingen), in centen.
     *  2. `projected` — VERWACHTE terugkerende omzet op basis van de huidige
     *     actieve abonnementen (MRR-run-rate: maandbedrag genormaliseerd, dan
     *     ×1/×3/×6/×12 voor de komende maand/kwartaal/half jaar/jaar). Dit is
     *     een projectie zonder groei/verloop, puur "wat loopt er nu".
     *
     * Vereist Stripe; zonder secret of bij een Stripe-storing geeft het net
     * `available:false` terug zodat het dashboard niet breekt.
     */
    public function getRevenue()
    {
        // Lokale abonnement-cijfers (geen Stripe nodig) — altijd meesturen,
        // ook als de Stripe-omzet even onbeschikbaar is.
        $base = [
            'active_by_plan' => $this->activeSubscriptionsByPlan(),
            'cancellations'  => $this->cancellationsPerMonth(12),
        ];

        $secret = config('billing.stripe_secret');
        if (! $secret) {
            return response()->json(array_merge($base, ['available' => false, 'reason' => 'no_stripe']), 200);
        }

        // Lopende kalenderperiodes, telkens vanaf de start tot nu (epoch-seconden).
        $now       = now();
        $month     = (int) $now->month;
        $halfStart = $month <= 6
            ? $now->copy()->startOfYear()
            : $now->copy()->startOfYear()->addMonths(6);   // H2 begint 1 juli

        $starts = [
            'today'     => $now->copy()->startOfDay()->timestamp,
            'week'      => $now->copy()->startOfWeek()->timestamp,
            'month'     => $now->copy()->startOfMonth()->timestamp,
            'quarter'   => $now->copy()->startOfQuarter()->timestamp,
            'half_year' => $halfStart->timestamp,
            'year'      => $now->copy()->startOfYear()->timestamp,
        ];

        $income   = array_fill_keys(array_keys($starts), 0);
        $currency = 'EUR';

        try {
            $stripe = new \Stripe\StripeClient($secret);

            // Alle geslaagde charges sinds jaarbegin — één keer ophalen, dan
            // per periode optellen. autoPagingIterator regelt de paginatie.
            foreach (
                $stripe->charges->all([
                    'created' => ['gte' => $starts['year']],
                    'limit'   => 100,
                ])->autoPagingIterator() as $ch
            ) {
                if (($ch->status ?? null) !== 'succeeded' || ! ($ch->paid ?? false)) {
                    continue;
                }
                $net = (int) ($ch->amount ?? 0) - (int) ($ch->amount_refunded ?? 0);
                if ($net <= 0) {
                    continue;
                }
                $ts = (int) $ch->created;
                foreach ($starts as $key => $start) {
                    if ($ts >= $start) {
                        $income[$key] += $net;
                    }
                }
                if (! empty($ch->currency)) {
                    $currency = strtoupper($ch->currency);
                }
            }

            // ── Verwachte MRR uit actieve abonnementen ──────────────────────
            $active = Subscription::where('stripe_status', 'active')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->get(['stripe_price', 'quantity']);

            $priceCache = [];
            $mrrCents   = 0.0;
            foreach ($active as $sub) {
                $pid = $sub->stripe_price;
                if (! $pid) {
                    continue;
                }
                if (! array_key_exists($pid, $priceCache)) {
                    try {
                        $priceCache[$pid] = $stripe->prices->retrieve($pid, []);
                    } catch (\Throwable $e) {
                        $priceCache[$pid] = null;
                    }
                }
                $price = $priceCache[$pid];
                if (! $price || empty($price->unit_amount)) {
                    continue;
                }
                $amount        = (float) $price->unit_amount * max(1, (int) $sub->quantity);
                $interval      = $price->recurring->interval ?? 'month';
                $intervalCount = max(1, (int) ($price->recurring->interval_count ?? 1));
                // Normaliseer naar maandbedrag.
                $monthly = match ($interval) {
                    'year'  => $amount / (12 * $intervalCount),
                    'week'  => $amount * 52 / (12 * $intervalCount),
                    'day'   => $amount * 365 / (12 * $intervalCount),
                    default => $amount / $intervalCount,   // 'month'
                };
                $mrrCents += $monthly;
                if (! empty($price->currency)) {
                    $currency = strtoupper($price->currency);
                }
            }

            $mrr = (int) round($mrrCents);

            return response()->json(array_merge($base, [
                'available'            => true,
                'currency'             => $currency,
                'income'               => $income,   // centen, per periode
                'mrr'                  => $mrr,      // centen/maand
                'arr'                  => $mrr * 12,
                'active_subscriptions' => $active->count(),
                'projected'            => [
                    'month'     => $mrr,
                    'quarter'   => $mrr * 3,
                    'half_year' => $mrr * 6,
                    'year'      => $mrr * 12,
                ],
                'generated_at'         => $now->toIso8601String(),
            ]), 200);

        } catch (\Throwable $e) {
            Log::warning('[admin] revenue ophalen mislukt: ' . $e->getMessage());
            return response()->json(array_merge($base, [
                'available' => false,
                'reason'    => 'stripe_error',
                'message'   => $e->getMessage(),
            ]), 200);
        }
    }

    /**
     * Actieve abonnementen gegroepeerd per plan-type (pro/team × maand/jaar).
     * Vertaalt de opgeslagen Stripe-Price-ID's naar plan-sleutels via de
     * effectieve prijs-map; onbekende prijzen belanden onder "Overig".
     */
    private function activeSubscriptionsByPlan(): array
    {
        $labels = [
            'pro_monthly'  => 'Pro — maandelijks',
            'pro_yearly'   => 'Pro — jaarlijks',
            'team_monthly' => 'Team — maandelijks',
            'team_yearly'  => 'Team — jaarlijks',
        ];

        // Price-ID → plan-sleutel (omgekeerde van effectiveMap()).
        $priceToKey = [];
        foreach (\App\Http\Controllers\AdminBillingController::effectiveMap() as $key => $priceId) {
            if ($priceId) {
                $priceToKey[$priceId] = $key;
            }
        }

        $rows = Subscription::where('stripe_status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->selectRaw('stripe_price, COUNT(*) as c')
            ->groupBy('stripe_price')
            ->get();

        $out = [];
        foreach ($labels as $key => $label) {
            $out[$key] = ['key' => $key, 'label' => $label, 'count' => 0];
        }
        $other = ['key' => 'other', 'label' => 'Overig', 'count' => 0];

        foreach ($rows as $row) {
            $key = $priceToKey[$row->stripe_price] ?? null;
            $count = (int) $row->c;
            if ($key && isset($out[$key])) {
                $out[$key]['count'] += $count;
            } else {
                $other['count'] += $count;
            }
        }

        $result = array_values($out);
        if ($other['count'] > 0) {
            $result[] = $other;
        }

        return $result;
    }

    /**
     * Opzeggingen per maand over de laatste $months maanden. Telt abonnementen
     * met status 'canceled', gebucket op de maand van de einddatum (of, bij
     * ontbreken, de laatste wijziging). Retourneert altijd een volledige reeks
     * (ook maanden met 0) zodat de grafiek een nette tijdlijn toont.
     */
    private function cancellationsPerMonth(int $months = 12): array
    {
        $start = now()->copy()->startOfMonth()->subMonths($months - 1);

        $rows = Subscription::where('stripe_status', 'canceled')
            ->where(function ($q) use ($start) {
                $q->where('ends_at', '>=', $start)
                  ->orWhere(function ($q2) use ($start) {
                      $q2->whereNull('ends_at')->where('updated_at', '>=', $start);
                  });
            })
            ->get(['ends_at', 'updated_at']);

        // Lege maandbuckets 'Y-m' → 0.
        $buckets = [];
        $cursor  = $start->copy();
        for ($i = 0; $i < $months; $i++) {
            $buckets[$cursor->format('Y-m')] = 0;
            $cursor->addMonth();
        }

        foreach ($rows as $row) {
            $date = $row->ends_at ?: $row->updated_at;
            if (! $date) {
                continue;
            }
            $key = \Illuminate\Support\Carbon::parse($date)->format('Y-m');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        $out = [];
        foreach ($buckets as $ym => $count) {
            $out[] = ['month' => $ym, 'count' => $count];
        }

        return $out;
    }

    /**
     * Apparaten waarop een gebruiker de app heeft gebruikt (iPhone/iPad/Android),
     * met model, OS- en appversie en laatst actief. "ingelogd" = er is nog een
     * niet-verlopen token aan het apparaat gekoppeld.
     */
    public function getUserDevices($userId)
    {
        $user = User::findOrFail($userId);
        $devices = \App\Models\ConnectDevice::where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn ($d) => [
                'id'           => $d->id,
                'platform'     => $d->platform,
                'model'        => $d->model,
                'model_name'   => \App\Support\DeviceInfo::modelName($d->model),
                'os_version'   => $d->os_version,
                'app_version'  => $d->app_version,
                'browser'      => $d->name,
                'identifier'   => $d->vendor_id ?: null,
                'logged_in'    => $d->is_connected,
                'revoked'      => (bool) $d->revoked_at,
                'last_seen_at' => optional($d->last_seen_at)->toIso8601String(),
                'first_seen_at'=> optional($d->created_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $devices]);
    }

    /**
     * Get full detail for a single user (profile, maps, teams, billing).
     */
    public function getUser($userId)
    {
        try {
            $user = User::with([
                'teams' => fn ($q) => $q->withCount('members'),
                'subscriptions',
            ])->findOrFail($userId);

            // Alleen tellingen — geen titels/datums van kaarten, routekaarten
            // of missies inladen (bewust beperkt voor het admin-overzicht).
            $mapsCount      = Map::where('owner_id', $user->id)->count();
            $routeMapsCount = RouteMap::where('owner_id', $user->id)->count();
            $missionsCount  = Mission::where('owner_id', $user->id)->count();

            return response()->json([
                'user' => [
                    'id'                => $user->id,
                    'first_name'        => $user->first_name,
                    'last_name'         => $user->last_name,
                    'name'              => $user->full_name,
                    'email'             => $user->email,
                    'is_admin'          => (bool) $user->is_admin,
                    'is_demo'           => (bool) $user->is_demo,
                    'language'          => $user->language,
                    'view_only'         => $user->isViewOnly(),
                    'email_verified_at' => $user->email_verified_at,
                    'created_at'        => $user->created_at,
                    'updated_at'        => $user->updated_at,
                    'last_seen_at'      => $user->last_seen_at,
                    'archived_at'       => $user->archived_at,
                    'plan'              => $user->plan(),
                    'subscribed'        => $user->subscribed(),
                    'trial_ends_at'     => $user->trial_ends_at,
                    'on_trial'          => $user->onAppTrial(),
                    'trial_days_left'   => $user->trialDaysLeft(),
                    'has_premium'       => $user->hasPremiumAccess(),
                    'stripe_id'         => $user->stripe_id,
                    'pm_type'           => $user->pm_type,
                    'pm_last_four'      => $user->pm_last_four,
                ],
                'teams' => $user->teams->map(function ($team) {
                    return [
                        'id'            => $team->id,
                        'name'          => $team->name,
                        'description'   => $team->description,
                        'color'         => $team->color,
                        'members_count' => $team->members_count,
                    ];
                }),
                // Partneraanmelding (partners.milmap.nl) — zo ziet de admin op
                // de gebruikerskaart direct of iemand partner is/wil worden.
                'connect_organizations' => class_exists(\App\Models\ConnectOrganization::class) && \Illuminate\Support\Facades\Schema::hasTable('connect_members')
                    ? \App\Models\ConnectOrganization::with('licenses')->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->get(['id', 'name', 'status', 'seat_limit', 'monthly_price_cents', 'currency', 'starts_at', 'ends_at'])
                    : [],
                'partner' => $user->partner ? [
                    'id'                     => $user->partner->id,
                    'status'                 => $user->partner->status,
                    'company_name'           => $user->partner->company_name,
                    'referral_code'          => $user->partner->referral_code,
                    'commission_rate'        => $user->partner->commission_rate,
                    'discount_rate'          => $user->partner->discount_rate,
                    'agreement_accepted_at'  => $user->partner->agreement_accepted_at?->toISOString(),
                    'agreement_confirmed_at' => $user->partner->agreement_confirmed_at?->toISOString(),
                    'created_at'             => $user->partner->created_at?->toISOString(),
                ] : null,
                'subscriptions' => $user->subscriptions->map(function ($sub) {
                    return [
                        'id'            => $sub->id,
                        'type'          => $sub->type,
                        'stripe_status' => $sub->stripe_status,
                        'stripe_price'  => $sub->stripe_price,
                        'quantity'      => $sub->quantity,
                        'trial_ends_at' => $sub->trial_ends_at,
                        'ends_at'       => $sub->ends_at,
                        'created_at'    => $sub->created_at,
                    ];
                }),
                'stats' => [
                    'total_maps'          => $mapsCount,
                    'total_routemaps'     => $routeMapsCount,
                    'total_missions'      => $missionsCount,
                    'total_teams'         => $user->teams->count(),
                    'total_subscriptions' => $user->subscriptions->count(),
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error'   => 'not_found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij ophalen gebruiker',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Markeer een gebruiker als demo-account (of maak dat ongedaan).
     *
     * Demo-accounts zijn eigen test-/presentatie-accounts: ze tellen niet mee
     * in de admin-statistieken en de app stopt er de gebruiksanalyse voor
     * (zie setTrackingUser in de frontend).
     */
    public function setDemo(Request $request, $userId)
    {
        $data = $request->validate(['is_demo' => 'required|boolean']);

        try {
            $user = User::findOrFail($userId);
            $user->is_demo = (bool) $data['is_demo'];
            $user->save();

            return response()->json([
                'message' => $user->is_demo
                    ? 'Gebruiker gemarkeerd als demo-account'
                    : 'Demo-markering verwijderd',
                'is_demo' => (bool) $user->is_demo,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Gebruiker niet gevonden', 'error' => 'not_found'], 404);
        }
    }

    /**
     * Delete a user and cascade related data
     */
    public function deleteUser($userId)
    {
        try {
            // Prevent self-deletion
            if (Auth::id() == $userId) {
                return response()->json([
                    'message' => 'Je kunt jezelf niet verwijderen',
                    'error' => 'cannot_delete_self'
                ], 403);
            }

            $user = User::findOrFail($userId);

            // Gegevens vastleggen vóór de verwijdering: daarna is er niets meer
            // om een bevestigingsmail mee te adresseren.
            $email = $user->email;
            $naam  = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $email;
            // Engels is de standaardtaal; alleen een account dat expliciet op
            // Nederlands staat krijgt de Nederlandse tekst.
            $taal  = strtolower(substr((string) ($user->language ?? ''), 0, 2)) === 'nl' ? 'nl' : 'en';

            // Lopend abonnement opzeggen. Gebeurt hier expliciet omdat de
            // databank dat niet kan opruimen: blijft het staan, dan betaalt de
            // klant door voor een account dat niet meer bestaat. Zelfde aanpak
            // als bij zelf-verwijderen (AccountDeletionController::cancelStripe).
            $hadAbonnement = $this->cancelStripeSubscriptions($user);

            // Daarna de Stripe-klant opruimen, zelfde regel als na de bedenktijd
            // bij zelf-verwijderen: nooit iets betaald → klant verwijderen;
            // wel betaald → klant blijft als administratie, maar alles stop
            // (abonnementen opgezegd, betaalmethodes ontkoppeld).
            app(\App\Services\StripeCustomerCleanup::class)->purgeOrDeactivate($user->stripe_id, $user->id);

            // Delete user (this cascades to maps and routemaps due to foreign key constraints)
            $user->delete();

            // Bevestiging naar de gebruiker: account gesloten, abonnement
            // stopgezet, en wat we nog bewaren bewaren we alleen voor de
            // wettelijk verplichte administratie. Best-effort — een mail die
            // niet aankomt mag de verwijdering niet ongedaan maken.
            try {
                Mail::to($email)->locale($taal)->send(new AccountClosedMail($naam, $hadAbonnement));
            } catch (\Throwable $e) {
                Log::error('[admin-delete-user] bevestigingsmail niet verstuurd', [
                    'user_id' => $userId,
                    'email'   => $email,
                    'error'   => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Gebruiker verwijderd',
                'user_id' => $userId
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error' => 'not_found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij verwijdering',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Zegt alle lopende Stripe-abonnementen van een gebruiker op en meldt of er
     * er iets op te zeggen viel. Best-effort: mislukt een opzegging, dan loggen
     * we dat zodat het handmatig rechtgezet kan worden — het mag het
     * verwijderen van de gebruiker niet tegenhouden.
     */
    private function cancelStripeSubscriptions(User $user): bool
    {
        $ids = Subscription::where('user_id', $user->id)
            ->whereNotNull('stripe_id')
            ->where('stripe_status', '!=', 'canceled')
            ->pluck('stripe_id');

        if ($ids->isEmpty()) {
            return false;
        }

        $secret = config('billing.stripe_secret');
        if (! $secret) {
            Log::warning('[admin-delete-user] geen Stripe-sleutel; abonnement niet opgezegd', [
                'user_id' => $user->id,
            ]);

            return true;
        }

        $stripe = new \Stripe\StripeClient(['api_key' => $secret]);
        foreach ($ids as $stripeId) {
            try {
                $stripe->subscriptions->cancel($stripeId, []);
            } catch (\Throwable $e) {
                Log::warning('[admin-delete-user] Stripe-abonnement opzeggen mislukt', [
                    'user_id'      => $user->id,
                    'subscription' => $stripeId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * Send password reset link to user
     */
    public function resetUserPassword($userId)
    {
        try {
            $user = User::findOrFail($userId);

            // Connect-apparaataccounts (@connect.milmap.nl) hebben geen mailbox en
            // loggen in via apparaatbinding. 422 i.p.v. 403: de admin-client logt
            // bij een 403 op /api/v1/admin uit, en abort() zou de catch hieronder raken.
            if ($user->connect_device_account) {
                return response()->json([
                    'message' => 'Connect-apparaataccounts gebruiken apparaatlogin en kunnen geen wachtwoord instellen of wijzigen.',
                    'error' => 'connect_device_account'
                ], 422);
            }

            // Zelfde flow als de publieke wachtwoord-reset
            // (PasswordResetController::sendResetLink): een custom e-mail met een
            // link naar het frontend-resetscherm. De standaard Laravel-notificatie
            // (sendPasswordResetNotification) werkt hier NIET omdat deze API-app
            // geen `password.reset`-webroute heeft → RouteNotFoundException.
            $token = Password::createToken($user);
            $resetUrl = config('auth.passwords.users.url')
                . '?token=' . $token
                . '&email=' . urlencode($user->email);

            Mail::to($user)->send(new ResetPasswordMail($resetUrl));

            return response()->json([
                'message' => 'Wachtwoord reset link verzonden naar ' . $user->email
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error' => 'not_found'
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[admin-password-reset] kon reset-link niet versturen: ' . $e->getMessage());
            return response()->json([
                'message' => 'Fout bij verzending',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/v1/admin/users/{userId}/grant-access
     * Ken een gebruiker (extra) gratis premium-toegang toe door de app-proef te
     * verlengen. Zet `trial_ends_at` op N dagen vanaf nu — of vanaf de huidige
     * proefeinddatum als die verder in de toekomst ligt — zodat een lopende proef
     * nooit wordt ingekort. Dit geeft direct volledige toegang
     * (User::hasPremiumAccess()) zonder Stripe, ideaal om een klant/eenheid te
     * onboarden of een verlopen proef te verlengen.
     */
    public function grantAccess(Request $request, $userId)
    {
        $data = $request->validate([
            'days' => 'required|integer|min:1|max:3650',
        ]);

        try {
            $user = User::findOrFail($userId);

            $base = ($user->trial_ends_at && $user->trial_ends_at->isFuture())
                ? $user->trial_ends_at->copy()
                : now();
            $newEnd = $base->addDays((int) $data['days']);

            $user->forceFill(['trial_ends_at' => $newEnd])->save();

            // Gratis toegang telt als echtheidsbewijs → e-mail meteen verifiëren
            // zodat de verificatie-wall deze klant niet alsnog blokkeert.
            $user->markEmailVerified();

            return response()->json([
                'message'       => "Gratis toegang verleend tot {$newEnd->format('d-m-Y')}.",
                'trial_ends_at' => $newEnd->toIso8601String(),
                'premium'       => $user->fresh()->premiumState(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Gebruiker niet gevonden', 'error' => 'not_found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Fout bij toekennen toegang', 'error' => $e->getMessage()], 500);
        }
    }

    /** Maak vanuit het admin-gebruikersprofiel een Connect-licentie aan. */
    public function createConnectOrganization(Request $request, $userId)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'seats' => 'required|integer|min:1|max:100000',
            'monthly_price' => 'required|numeric|min:0|max:10000000',
            'months' => 'required|integer|min:1|max:120',
            'trial_days' => 'nullable|integer|min:1|max:365',
        ]);

        $user = User::findOrFail($userId);
        abort_if($user->isArchived(), 422, 'Een gearchiveerd account kan geen Connect-eigenaar worden.');
        abort_if(! \Illuminate\Support\Facades\Schema::hasTable('connect_organizations'), 503, 'Connect-migratie ontbreekt op de server.');
        abort_if(\App\Models\ConnectOrganization::whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active'))->exists(), 422, 'Deze gebruiker heeft al een Connect-organisatie.');

        $organization = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(\App\Models\ConnectOrganization::whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active'))->exists(), 422, 'Deze gebruiker heeft al een Connect-organisatie.');
            $organization = \App\Models\ConnectOrganization::create([
                'name' => $data['name'], 'seat_limit' => $data['seats'],
                'monthly_price_cents' => (int) round(((float) $data['monthly_price']) * 100),
                'monthly_price_per_seat_cents' => (int) round(((float) $data['monthly_price']) * 100 / max(1, (int) $data['seats'])), 'currency' => 'EUR',
                'status' => 'active', 'starts_at' => now(), 'ends_at' => ! empty($data['trial_days']) ? now()->addDays($data['trial_days']) : now()->addMonthsNoOverflow($data['months']),
            ]);
            \App\Models\ConnectMember::create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => $user->first_name ?: 'Beheerder',
                'last_name' => $user->last_name ?: '', 'email' => mb_strtolower($user->email), 'role' => 'owner', 'status' => 'active', 'tags' => [], 'accepted_at' => now(),
            ]);
            app(\App\Services\ConnectLicensing::class)->preserveExisting($organization);
            if (! empty($data['trial_days'])) $organization->licenses()->update(['is_trial' => true, 'name' => 'Proeflicentie']);
            return $organization->load('licenses');
        });
        return response()->json(['message' => 'Connect-organisatie aangemaakt.', 'organization' => $organization], 201);
    }

    /** Koppel een klantspecifieke Stripe-prijs (Connect-product) aan de organisatie; daarmee koopt de klant zijn licentie. */
    public function setConnectStripePrice(Request $request, $userId, $organizationId)
    {
        $data = $request->validate(['price_id' => 'nullable|string|max:100|regex:/^price_[A-Za-z0-9]+$/']);
        $organization = \App\Models\ConnectOrganization::whereKey($organizationId)
            ->whereHas('members', fn ($q) => $q->where('user_id', (int) $userId)->where('role', 'owner')->where('status', 'active'))->firstOrFail();
        if ($data['price_id'] ?? null) {
            abort_unless(config('billing.stripe_secret'), 503, 'Stripe is nog niet geconfigureerd.');
            try { $price = (new \Stripe\StripeClient(['api_key' => config('billing.stripe_secret')]))->prices->retrieve($data['price_id'], []); }
            catch (\Stripe\Exception\InvalidRequestException $e) { abort(422, 'Deze prijs bestaat niet in Stripe.'); }
            abort_unless($price->active && $price->type === 'recurring' && $price->recurring->interval === 'month' && $price->recurring->interval_count === 1 && $price->unit_amount > 0, 422, 'Gebruik een actieve vaste maandprijs per seat.');
        }
        $organization->forceFill(['stripe_price_id' => $data['price_id'] ?? null])->save();
        return response()->json(['message' => 'Stripe-prijs bijgewerkt.', 'organization' => $organization->fresh()]);
    }

    /** Voeg een apart enterprise-product met een eigen looptijd toe. */
    public function addConnectLicense(Request $request, $userId, $organizationId)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160', 'seats' => 'required|integer|min:1|max:100000',
            'monthly_price' => 'required|numeric|min:0|max:10000000', 'months' => 'required|integer|min:1|max:120',
            'request_id' => 'required|uuid', 'trial_days' => 'nullable|integer|min:1|max:365',
        ]);
        $user = User::findOrFail($userId);
        abort_if($user->isArchived(), 422, 'Een gearchiveerd account kan geen licentie krijgen.');
        $organization = \App\Models\ConnectOrganization::whereKey($organizationId)
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active'))->firstOrFail();
        abort_unless($organization->status === 'active', 422, 'De Connect-organisatie is niet actief.');
        $license = app(\App\Services\ConnectLicensing::class)->add($organization, [
            'name' => $data['name'], 'seat_count' => $data['seats'],
            'monthly_price_cents' => (int) round(((float) $data['monthly_price']) * 100), 'currency' => $organization->currency,
            'starts_at' => now(), 'ends_at' => ! empty($data['trial_days']) ? now()->addDays($data['trial_days']) : now()->addMonthsNoOverflow($data['months']),
            'is_trial' => ! empty($data['trial_days']), 'source_reference' => 'admin:'.$organization->id.':'.$data['request_id'],
        ]);
        return response()->json(['message' => 'Enterprise-licentie toegevoegd.', 'license' => $license, 'organization' => $organization->fresh()->load('licenses')], 201);
    }

    /**
     * POST /api/v1/admin/users/{userId}/verify-email
     * Markeer het e-mailadres van een gebruiker handmatig als geverifieerd (bv.
     * na telefonisch/persoonlijk contact), zodat de verificatie-wall verdwijnt.
     */
    public function verifyUserEmail($userId)
    {
        try {
            $user = User::findOrFail($userId);
            $changed = $user->markEmailVerified();

            return response()->json([
                'message'           => $changed
                    ? 'E-mailadres gemarkeerd als geverifieerd.'
                    : 'E-mailadres was al geverifieerd.',
                'email_verified_at' => $user->fresh()->email_verified_at,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Gebruiker niet gevonden', 'error' => 'not_found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Fout bij verifiëren', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/admin/users/{userId}/send-mail
     * Eenmalige, persoonlijke mail vanuit de admin naar één gebruiker (Mails-tab
     * op de gebruikersdetailpagina). Bewust GEEN campagne: geen template, geen
     * categorie en dus ook geen opt-out-afhandeling — dit is het equivalent van
     * een handmatig support-antwoord, dat de beheerder gericht verstuurt.
     *
     * Loggen hoeft hier niet: de MessageSending/MessageSent-listeners in
     * AppServiceProvider schrijven élke uitgaande mail naar sent_emails (incl.
     * open-/kliktracking), waardoor de mail vanzelf in diezelfde Mails-tab
     * verschijnt.
     */
    /**
     * GET /api/v1/admin/mail-meta
     * Van- en reply-to-adres voor handmatige mails, zodat de compose-preview
     * in de admin-UI dit niet hoeft te hardcoden.
     */
    public function mailMeta()
    {
        return response()->json([
            'from_address'  => config('mail.from.address'),
            'from_name'     => config('mail.from.name'),
            'reply_to'      => config('mail.admin_reply_to', 'support@milmap.nl'),
        ]);
    }

    public function sendUserMail(Request $request, $userId)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body'    => 'required|string|max:10000',
        ]);

        try {
            $user = User::findOrFail($userId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Gebruiker niet gevonden', 'error' => 'not_found'], 404);
        }

        if (! $user->email) {
            return response()->json(['message' => 'Deze gebruiker heeft geen e-mailadres.'], 422);
        }

        try {
            // Platte tekst uit het formulier: e() tegen HTML-injectie, nl2br zodat
            // de regelafbrekingen van de beheerder overeind blijven in de mail.
            $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.6;color:#0f172a">'
                . nl2br(e($data['body'])) . '</div>';

            Mail::html($html, function ($m) use ($user, $data) {
                $m->to($user->email, $user->name ?: null)
                  ->subject($data['subject'])
                  ->replyTo(config('mail.admin_reply_to', 'support@milmap.nl'));
            });
        } catch (\Throwable $e) {
            Log::error('[admin] handmatige mail naar gebruiker mislukt: ' . $e->getMessage());
            return response()->json(['message' => 'E-mail versturen mislukt: ' . $e->getMessage()], 500);
        }

        Log::info("[admin] handmatige mail verstuurd naar user {$user->id} ({$user->email}) door admin " . (Auth::id() ?? '?'));

        return response()->json(['message' => 'E-mail verstuurd naar ' . $user->email], 200);
    }

    /** Send a support chat message to a user; delivered through the normal chat channel. */
    public function sendUserChat(Request $request, $userId)
    {
        abort_unless(Auth::user()?->is_admin, 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $user = User::findOrFail($userId);
        $admin = Auth::user();
        $conversation = Conversation::query()
            ->where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $admin->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))
            ->first();
        if (!$conversation) {
            $conversation = Conversation::create(['type' => 'direct', 'created_by' => $admin->id]);
            $conversation->participants()->attach([$admin->id, $user->id]);
        }
        $message = Message::create([
            'conversation_id' => $conversation->id, 'sender_id' => $admin->id,
            'ciphertext' => $data['body'], 'ciphertext_self' => $data['body'],
            'encryption' => 'none', 'type' => 'text',
        ]);
        $conversation->update(['last_message_at' => $message->created_at]);
        try { broadcast(new MessageCreated($message))->toOthers(); } catch (\Throwable $e) { Log::warning('[admin-chat] broadcast failed: '.$e->getMessage()); }
        return response()->json(['message' => $message->forViewer($user->id)], 201);
    }

    /**
     * GET /api/v1/admin/client-errors
     * Recent front-end errors captured from the browser (rolling JSON log),
     * so an admin can review them and forward them to Claude for a fix.
     */
    public function clientErrors()
    {
        $file = storage_path('logs/frontend-errors.json');
        $errors = [];

        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $errors = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        return response()->json([
            'errors' => $errors,
            'count'  => count($errors),
        ]);
    }

    /**
     * DELETE /api/v1/admin/client-errors
     * Clear the captured front-end error log.
     */
    public function clearClientErrors(Request $request)
    {
        $file = storage_path('logs/frontend-errors.json');
        if (! file_exists($file)) {
            return response()->json(['ok' => true, 'verwijderd' => 0]);
        }

        // Optioneel filteren op soort. Sta je in de admin op "alleen api_error"
        // te kijken, dan hoort Wissen ook alléén die weg te halen — anders
        // gooi je met één klik ook alles weg wat je niet zag staan.
        $soort = $request->query('type');

        if (! $soort) {
            @file_put_contents($file, json_encode([]));

            return response()->json(['ok' => true]);
        }

        $raw = @file_get_contents($file);
        $errors = $raw ? (json_decode($raw, true) ?: []) : [];

        $over = array_values(array_filter(
            $errors,
            fn ($e) => ($e['type'] ?? '') !== $soort
        ));

        @file_put_contents($file, json_encode($over, JSON_PRETTY_PRINT));

        return response()->json([
            'ok'          => true,
            'verwijderd'  => count($errors) - count($over),
        ]);
    }

    /**
     * GET /api/v1/admin/sidebar-counts
     * Aantal "nieuw"/nog-te-behandelen items per Sales-onderdeel, voor de
     * badges in de admin-nav (Partners/Leads/Feature requests/Sales-CRM).
     */
    public function sidebarCounts()
    {
        return response()->json([
            'partners'         => \App\Models\Partner::where('status', 'pending')->count(),
            'leads'            => \App\Models\Lead::whereNull('notified_at')->count(),
            'feature_requests' => \App\Models\FeatureRequest::where('status', 'new')->count(),
            'crm_deals'        => \App\Models\CrmDeal::where('status', 'open')->where('stage', 'lead')->count(),
        ]);
    }

    /**
     * GET /api/v1/admin/reports/range-summary?from=Y-m-d&to=Y-m-d
     * Omzet (Stripe) + nieuwe gebruikers binnen een exacte periode, voor het
     * periode-filter op de Rapportage-pagina (vandaag/gisteren/week/maand/
     * kwartaal/jaar). Losse, lichte query — geen MRR-projectie zoals getRevenue().
     */
    public function reportsRangeSummary(Request $request)
    {
        $from = $request->query('from');
        $to   = $request->query('to');
        if (! $from || ! $to) {
            return response()->json(['message' => 'from en to zijn verplicht (Y-m-d).'], 422);
        }

        $fromTs = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $toTs   = \Illuminate\Support\Carbon::parse($to)->endOfDay();

        $newUsers = User::where('is_demo', false)
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->count();

        $secret = config('billing.stripe_secret');
        if (! $secret) {
            return response()->json([
                'new_users' => $newUsers,
                'revenue'   => ['available' => false, 'reason' => 'no_stripe'],
            ]);
        }

        $total    = 0;
        $currency = 'EUR';
        try {
            $stripe = new \Stripe\StripeClient($secret);
            foreach (
                $stripe->charges->all([
                    'created' => ['gte' => $fromTs->timestamp, 'lte' => $toTs->timestamp],
                    'limit'   => 100,
                ])->autoPagingIterator() as $ch
            ) {
                if (($ch->status ?? null) !== 'succeeded' || ! ($ch->paid ?? false)) {
                    continue;
                }
                $net = (int) ($ch->amount ?? 0) - (int) ($ch->amount_refunded ?? 0);
                if ($net <= 0) {
                    continue;
                }
                $total += $net;
                if (! empty($ch->currency)) {
                    $currency = strtoupper($ch->currency);
                }
            }
        } catch (\Throwable $e) {
            return response()->json([
                'new_users' => $newUsers,
                'revenue'   => ['available' => false, 'reason' => 'stripe_error'],
            ]);
        }

        return response()->json([
            'new_users' => $newUsers,
            'revenue'   => [
                'available' => true,
                'amount'    => $total / 100,
                'currency'  => $currency,
            ],
        ]);
    }

    /** Maak vanuit het admin-gebruikersprofiel een Connect-licentie aan. */


    /** Voeg een apart enterprise-product met een eigen looptijd toe. */

}
