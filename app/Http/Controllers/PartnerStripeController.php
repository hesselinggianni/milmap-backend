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
            // Een eerder opgeslagen account kan ongeldig zijn (bv. aangemaakt in
            // een andere Stripe-modus, of verwijderd). Controleer dat en maak
            // het account zo nodig opnieuw aan, zodat een half-mislukte poging
            // de partner niet permanent blokkeert.
            if ($partner->stripe_account_id) {
                try {
                    $this->stripe->accounts->retrieve($partner->stripe_account_id);
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    Log::warning('[partner] opgeslagen Stripe-account ongeldig, opnieuw aanmaken', [
                        'partner' => $partner->id,
                        'account' => $partner->stripe_account_id,
                        'error'   => $e->getMessage(),
                    ]);
                    $partner->update(['stripe_account_id' => null]);
                    $partner->refresh();
                }
            }

            if (! $partner->stripe_account_id) {
                $account = $this->stripe->accounts->create([
                    'type'         => 'express',
                    'country'      => 'NL',
                    'email'        => $request->user()->email,
                    // Stripe staat een transfers-only platform niet standaard toe
                    // ('transfers' zonder 'card_payments' vereist aparte goed-
                    // keuring). We vragen daarom beide capabilities aan; de
                    // partner ontvangt in de praktijk alleen uitbetalingen.
                    'capabilities' => [
                        'transfers'     => ['requested' => true],
                        'card_payments' => ['requested' => true],
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
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Toon de werkelijke Stripe-fout: dat is de enige manier om te zien
            // wat er misgaat (Connect niet aan, platformprofiel onvolledig,
            // ontbrekende capability, live/test-mismatch, …). De partner-pagina
            // is authenticated, dus de melding zichtbaar maken is veilig.
            $stripeMsg = $e->getMessage();
            Log::error('[partner] Stripe-onboarding mislukt (Stripe API)', [
                'partner' => $partner->id,
                'type'    => get_class($e),
                'code'    => method_exists($e, 'getStripeCode') ? $e->getStripeCode() : null,
                'error'   => $stripeMsg,
            ]);

            $isConnectOff = stripos($stripeMsg, 'connect') !== false
                || stripos($stripeMsg, 'platform') !== false
                || stripos($stripeMsg, 'sign up for') !== false;

            return response()->json([
                'message' => $isConnectOff
                    ? 'Stripe Connect is nog niet (volledig) geactiveerd op het MilMap-account. Uitbetalingen kunnen pas gekoppeld worden zodra dat aanstaat.'
                    : 'Stripe kon de koppeling niet starten: ' . $stripeMsg,
            ], 502);
        } catch (\Throwable $e) {
            Log::error('[partner] Stripe-onboarding mislukt', [
                'partner' => $partner->id,
                'type'    => get_class($e),
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Stripe-koppeling is op dit moment niet beschikbaar. Probeer het later opnieuw.',
            ], 502);
        }

        return response()->json(['url' => $accountLink->url]);
    }

    // ── POST /v1/partner/stripe/dashboard ──────────────────────────
    // Opent het Stripe Express-dashboard van de partner (login-link), waar de
    // partner o.a. zijn bankrekening/uitbetaalgegevens kan bekijken en wijzigen.
    // Werkt alleen voor een afgerond account; is de onboarding nog niet klaar,
    // dan geven we een onboarding-/update-link terug zodat de partner eerst
    // afrondt.
    public function createDashboardLink(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        if (! $partner->isApproved()) {
            return response()->json(['message' => 'Je aanmelding is nog niet goedgekeurd.'], 403);
        }
        if (! config('billing.stripe_secret')) {
            return response()->json(['message' => 'Stripe is niet geconfigureerd.'], 503);
        }
        if (! $partner->stripe_account_id) {
            return response()->json(['message' => 'Er is nog geen Stripe-account gekoppeld.'], 409);
        }

        $partnerUrl = rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/');

        try {
            // Login-link naar het Express-dashboard (bankrekening beheren).
            $link = $this->stripe->accounts->createLoginLink($partner->stripe_account_id);

            return response()->json(['url' => $link->url]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Account bestaat maar onboarding is nog niet afgerond: dan kan er
            // (nog) geen login-link, wél een update-/onboarding-link.
            Log::info('[partner] Express-login-link niet mogelijk, val terug op onboarding', [
                'partner' => $partner->id, 'error' => $e->getMessage(),
            ]);

            try {
                $accountLink = $this->stripe->accountLinks->create([
                    'account'     => $partner->stripe_account_id,
                    'refresh_url' => "{$partnerUrl}/stripe?refresh=1",
                    'return_url'  => "{$partnerUrl}/stripe?complete=1",
                    'type'        => 'account_onboarding',
                ]);

                return response()->json(['url' => $accountLink->url]);
            } catch (\Throwable $inner) {
                Log::error('[partner] Stripe-dashboardlink mislukt', [
                    'partner' => $partner->id, 'error' => $inner->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Stripe-dashboard is op dit moment niet beschikbaar. Probeer het later opnieuw.',
                ], 502);
            }
        } catch (\Throwable $e) {
            Log::error('[partner] Stripe-dashboardlink mislukt', [
                'partner' => $partner->id, 'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Stripe-dashboard is op dit moment niet beschikbaar. Probeer het later opnieuw.',
            ], 502);
        }
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
