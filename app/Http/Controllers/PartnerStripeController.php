<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Stripe-Connect-koppeling voor partners (Express-accounts). De partner
 * doorloopt de Stripe-hosted onboarding; daarna kunnen commissies via
 * Transfers naar het gekoppelde account worden uitbetaald (partners:payout).
 */
class PartnerStripeController extends Controller
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(['api_key' => config('billing.stripe_secret') ?: null]);
    }

    // ── POST /v1/partner/stripe/onboard ────────────────────────────
    public function createOnboardingLink(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        if (! $partner->isApproved()) {
            return response()->json(['message' => 'Je aanmelding is nog niet goedgekeurd.'], 403);
        }
        if (! config('billing.stripe_secret')) {
            return response()->json(['message' => 'Stripe is niet geconfigureerd.'], 503);
        }

        $partnerUrl = rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/');

        try {
            if (! $partner->stripe_account_id) {
                $account = $this->stripe->accounts->create([
                    'type'         => 'express',
                    'country'      => 'NL',
                    'email'        => $request->user()->email,
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                    ],
                    'metadata'     => ['partner_id' => (string) $partner->id],
                ]);
                $partner->update(['stripe_account_id' => $account->id]);
            }

            $accountLink = $this->stripe->accountLinks->create([
                'account'     => $partner->stripe_account_id,
                'refresh_url' => "{$partnerUrl}/stripe?refresh=1",
                'return_url'  => "{$partnerUrl}/stripe?complete=1",
                'type'        => 'account_onboarding',
            ]);
        } catch (\Throwable $e) {
            Log::error('[partner] Stripe-onboarding mislukt', [
                'partner' => $partner->id, 'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Stripe-koppeling is op dit moment niet beschikbaar. Probeer het later opnieuw.',
            ], 502);
        }

        return response()->json(['url' => $accountLink->url]);
    }

    // ── GET /v1/partner/stripe/status ──────────────────────────────
    // Haalt de actuele accountstatus live bij Stripe op en synct de
    // onboarding-vlag (naast de account.updated-webhook, zodat de partner na
    // terugkeer van Stripe direct de juiste status ziet).
    public function status(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        if (! $partner->stripe_account_id) {
            return response()->json([
                'connected'           => false,
                'onboarding_complete' => false,
            ]);
        }

        $payoutsEnabled = $partner->stripe_onboarding_complete;
        try {
            $account        = $this->stripe->accounts->retrieve($partner->stripe_account_id);
            $payoutsEnabled = (bool) $account->payouts_enabled;

            if ($payoutsEnabled !== $partner->stripe_onboarding_complete) {
                $partner->update(['stripe_onboarding_complete' => $payoutsEnabled]);
            }
        } catch (\Throwable $e) {
            Log::warning('[partner] Stripe-status ophalen mislukt', [
                'partner' => $partner->id, 'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'connected'           => true,
            'onboarding_complete' => $payoutsEnabled,
        ]);
    }
}
