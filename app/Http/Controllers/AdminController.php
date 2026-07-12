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

class AdminController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            // Alleen tellingen — bewust geen kaarttitels/datums inladen.
            $baseUsers = User::select('id', 'first_name', 'last_name', 'email', 'is_admin', 'created_at')
                ->withCount('maps')
                ->get();

            // Per-gebruiker tellingen van routekaarten en missies in twee
            // groeps-queries (geen N+1, en zonder de rijen/titels te laden).
            $routeMapCounts = RouteMap::selectRaw('owner_id, COUNT(*) as c')
                ->groupBy('owner_id')
                ->pluck('c', 'owner_id');
            $missionCounts = Mission::selectRaw('owner_id, COUNT(*) as c')
                ->groupBy('owner_id')
                ->pluck('c', 'owner_id');

            $users = $baseUsers->map(function ($user) use ($routeMapCounts, $missionCounts) {
                return [
                    'id'               => $user->id,
                    'name'             => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'email'            => $user->email,
                    'is_admin'         => (bool) $user->is_admin,
                    'maps_count'       => (int) $user->maps_count,
                    'route_maps_count' => (int) ($routeMapCounts[$user->id] ?? 0),
                    'missions_count'   => (int) ($missionCounts[$user->id] ?? 0),
                    'created_at'       => $user->created_at,
                ];
            })->values();

            return response()->json([
                'users' => $users,
                'stats' => [
                    'total_users'     => $baseUsers->count(),
                    'total_maps'      => Map::count(),
                    'total_routemaps' => RouteMap::count(),
                    'total_missions'  => Mission::count(),
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
                    'language'          => $user->language,
                    'view_only'         => $user->isViewOnly(),
                    'email_verified_at' => $user->email_verified_at,
                    'created_at'        => $user->created_at,
                    'updated_at'        => $user->updated_at,
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

            // Delete user (this cascades to maps and routemaps due to foreign key constraints)
            $user->delete();

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
     * Send password reset link to user
     */
    public function resetUserPassword($userId)
    {
        try {
            $user = User::findOrFail($userId);

            // Send password reset notification
            $user->sendPasswordResetNotification(Password::createToken($user));

            return response()->json([
                'message' => 'Wachtwoord reset link verzonden naar ' . $user->email
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error' => 'not_found'
            ], 404);
        } catch (\Exception $e) {
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
    public function clearClientErrors()
    {
        $file = storage_path('logs/frontend-errors.json');
        if (file_exists($file)) {
            @file_put_contents($file, json_encode([]));
        }

        return response()->json(['ok' => true]);
    }
}
