<?php

namespace App\Http\Controllers;

use App\Models\PartnerCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data-endpoints voor het ingelogde partnerportaal (partners.milmap.nl).
 * Routes staan achter auth:sanctum + de 'partner'-middleware; de status
 * (pending/approved) wordt hier per response meegegeven zodat het portaal
 * de juiste pagina kan tonen.
 */
class PartnerDashboardController extends Controller
{
    // ── GET /v1/partner/dashboard ──────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        return response()->json([
            'partner' => [
                'id'                         => $partner->id,
                'company_name'               => $partner->company_name,
                'referral_code'              => $partner->referral_code,
                'referral_url'               => $partner->referralUrl(),
                'status'                     => $partner->status,
                'commission_rate'            => $partner->commission_rate,
                'discount_rate'              => $partner->discount_rate,
                'website'                    => $partner->website,
                'stripe_onboarding_complete' => $partner->stripe_onboarding_complete,
                'has_stripe_account'         => (bool) $partner->stripe_account_id,
                'approved_at'                => $partner->approved_at?->toISOString(),
                // Overeenkomst: het portaal gate't de referral-link hierop en
                // toont de acceptatie-/bevestigingsmomenten in het account.
                'agreement_accepted_at'      => $partner->agreement_accepted_at?->toISOString(),
                'agreement_confirmed_at'     => $partner->agreement_confirmed_at?->toISOString(),
                'agreement_complete'         => $partner->agreementComplete(),
            ],
            'stats' => $this->buildStats($partner),
        ]);
    }

    // ── GET /v1/partner/stats ──────────────────────────────────────
    public function stats(Request $request): JsonResponse
    {
        return response()->json(['stats' => $this->buildStats($request->user()->partner)]);
    }

    // ── GET /v1/partner/referrals ──────────────────────────────────
    // Doorverwezen gebruikers, geanonimiseerd tot wat de partner mag zien.
    public function referrals(Request $request): JsonResponse
    {
        $referrals = $request->user()->partner->referrals()
            ->with('referredUser:id,first_name,email,created_at')
            ->withSum(['commissions as total_commission' => fn ($q) => $q->whereIn('status', ['pending', 'paid'])], 'commission_amount')
            ->orderByDesc('converted_at')
            ->paginate(25);

        $referrals->getCollection()->transform(function ($r) {
            return [
                'id'               => $r->id,
                'converted_at'     => $r->converted_at?->toISOString(),
                'discount_applied' => $r->discount_applied,
                'commission_rate'  => $r->commission_rate_snapshot,
                'total_commission' => (float) ($r->total_commission ?? 0),
                // Privacy: alleen voornaam + gemaskeerd e-mailadres richting de partner.
                'user'             => [
                    'name'  => $r->referredUser?->first_name ?: 'Gebruiker',
                    'email' => $this->maskEmail($r->referredUser?->email),
                ],
            ];
        });

        return response()->json($referrals);
    }

    // ── GET /v1/partner/commissions ────────────────────────────────
    public function commissions(Request $request): JsonResponse
    {
        $commissions = $request->user()->partner->commissions()
            ->orderByDesc('created_at')
            ->paginate(25);

        $commissions->getCollection()->transform(fn ($c) => [
            'id'                => $c->id,
            'gross_amount'      => $c->gross_amount,
            'commission_amount' => $c->commission_amount,
            'status'            => $c->status,
            'paid_at'           => $c->paid_at?->toISOString(),
            'created_at'        => $c->created_at?->toISOString(),
            // Wanneer deze commissie (naar verwachting) wordt uitbetaald.
            'expected_payout_at' => $c->expectedPayoutDate()?->toISOString(),
        ]);

        return response()->json($commissions);
    }

    private function buildStats($partner): array
    {
        $monthStart = now()->startOfMonth();

        return [
            'total_referrals'      => $partner->referrals()->count(),
            'referrals_this_month' => $partner->referrals()->where('converted_at', '>=', $monthStart)->count(),
            'pending_earnings'     => $partner->pendingEarnings(),
            'total_earned'         => $partner->totalEarned(),
            'earned_this_month'    => (float) $partner->commissions()
                ->where('status', PartnerCommission::STATUS_PAID)
                ->where('paid_at', '>=', $monthStart)
                ->sum('commission_amount'),
            // Openstaande commissies gegroepeerd per verwachte uitbetaalmaand,
            // zodat de partner ziet wat er wanneer aankomt.
            'upcoming_payouts'     => $this->upcomingPayouts($partner),
        ];
    }

    /**
     * Groepeer alle pending commissies per verwachte uitbetaalmaand (1e van de
     * maand). Teruggegeven als oplopende lijst met periode, bedrag en aantal.
     */
    private function upcomingPayouts($partner): array
    {
        return $partner->commissions()
            ->where('status', PartnerCommission::STATUS_PENDING)
            ->get()
            ->groupBy(fn ($c) => $c->expectedPayoutDate()?->format('Y-m'))
            ->filter(fn ($group, $period) => $period !== '')
            ->map(fn ($group, $period) => [
                'period' => $period,                                            // 'YYYY-MM'
                'date'   => $group->first()->expectedPayoutDate()?->toISOString(),
                'amount' => round((float) $group->sum('commission_amount'), 2),
                'count'  => $group->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    private function maskEmail(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '—';
        }
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 2) . str_repeat('•', max(strlen($local) - 2, 1)) . '@' . $domain;
    }
}
