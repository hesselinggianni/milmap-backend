<?php

namespace App\Http\Controllers;

use App\Mail\PartnerApprovedMail;
use App\Models\Partner;
use App\Models\PartnerCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Partnerbeheer voor de admin-app (admin.milmap.nl): aanmeldingen beoordelen,
 * rates aanpassen en handmatig een uitbetalingsronde starten.
 */
class AdminPartnerController extends Controller
{
    // ── GET /admin/partners ────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Partner::with('user:id,first_name,last_name,email')
            ->withCount('referrals')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $partners = $query->get()->map(function (Partner $p) {
            return [
                'id'                         => $p->id,
                'user'                       => [
                    'id'    => $p->user?->id,
                    'name'  => trim(($p->user->first_name ?? '') . ' ' . ($p->user->last_name ?? '')),
                    'email' => $p->user?->email,
                ],
                'company_name'               => $p->company_name,
                'website'                    => $p->website,
                'description'                => $p->description,
                'referral_code'              => $p->referral_code,
                'referral_url'               => $p->referralUrl(),
                'status'                     => $p->status,
                'commission_rate'            => $p->commission_rate,
                'discount_rate'              => $p->discount_rate,
                'stripe_onboarding_complete' => $p->stripe_onboarding_complete,
                'agreement_accepted_at'      => $p->agreement_accepted_at?->toISOString(),
                'agreement_confirmed_at'     => $p->agreement_confirmed_at?->toISOString(),
                'agreement_version'          => $p->agreement_version,
                'referrals_count'            => $p->referrals_count,
                'pending_earnings'           => $p->pendingEarnings(),
                'total_earned'               => $p->totalEarned(),
                'approved_at'                => $p->approved_at?->toISOString(),
                'created_at'                 => $p->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'partners' => $partners,
            'summary'  => [
                'pending_applications' => Partner::where('status', Partner::STATUS_PENDING)->count(),
                'approved'             => Partner::where('status', Partner::STATUS_APPROVED)->count(),
                'open_commissions'     => (float) PartnerCommission::where('status', PartnerCommission::STATUS_PENDING)->sum('commission_amount'),
            ],
        ]);
    }

    // ── POST /admin/partners/{partner}/approve ─────────────────────
    public function approve(Partner $partner): JsonResponse
    {
        $partner->update([
            'status'      => Partner::STATUS_APPROVED,
            'approved_at' => $partner->approved_at ?? now(),
        ]);

        try {
            Mail::to($partner->user->email)->send(new PartnerApprovedMail($partner));
        } catch (\Throwable $e) {
            Log::warning('[partner] goedkeuringsmail mislukt: ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'status' => $partner->status]);
    }

    // ── POST /admin/partners/{partner}/suspend ─────────────────────
    public function suspend(Partner $partner): JsonResponse
    {
        $partner->update(['status' => Partner::STATUS_SUSPENDED]);

        return response()->json(['ok' => true, 'status' => $partner->status]);
    }

    // ── PUT /admin/partners/{partner}/rates ────────────────────────
    // Alleen voor NIEUWE referrals: bestaande houden hun snapshot-rates.
    public function updateRates(Request $request, Partner $partner): JsonResponse
    {
        $validated = $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100',
            'discount_rate'   => 'required|numeric|min:0|max:100',
        ]);

        $partner->update($validated);

        return response()->json(['ok' => true, 'partner' => $partner->only(['id', 'commission_rate', 'discount_rate'])]);
    }

    // ── POST /admin/partners/payouts ───────────────────────────────
    // Handmatig een uitbetalingsronde starten (zelfde command als de cron).
    public function triggerPayouts(): JsonResponse
    {
        Artisan::call('partners:payout');

        return response()->json([
            'ok'     => true,
            'output' => trim(Artisan::output()),
        ]);
    }
}
