<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerReferral;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Kernlogica van het partnersysteem:
 *
 * - attachReferral()      : koppel een nieuwe gebruiker aan een partner op basis
 *                           van de referral-code (bij registratie).
 * - discountCouponId()    : idempotente Stripe-coupon voor het kortings-
 *                           percentage van een partner (bv. milmap-partner-10),
 *                           toegepast op de checkout van doorverwezen gebruikers.
 * - applyReferralDiscount(): zet die coupon op de checkout-sessieparams.
 * - recordCommission()    : maak een pending commissie aan uit een betaalde
 *                           Stripe-factuur (invoice.paid webhook).
 * - markRefunded()        : draai een commissie terug bij een refund zolang hij
 *                           nog niet is uitbetaald.
 */
class PartnerService
{
    /**
     * Commissie geldt alleen over het eerste abonnementsjaar van de
     * doorverwezen gebruiker: betalingen binnen 12 maanden na diens eerste
     * betaalde factuur. Bij een jaarabonnement is dat dus eenmalig de eerste
     * jaarbetaling; bij een maandabonnement maximaal 12 maandtermijnen.
     */
    public const COMMISSION_WINDOW_MONTHS = 12;

    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(['api_key' => config('billing.stripe_secret') ?: null]);
    }

    public function isConfigured(): bool
    {
        return (bool) config('billing.stripe_secret');
    }

    /**
     * Koppel een zojuist geregistreerde gebruiker aan een goedgekeurde partner.
     * Onbekende, eigen of geschorste codes worden stil genegeerd — een foute
     * code mag een registratie nooit breken. Eén attributie per gebruiker
     * (unique op referred_user_id); de eerste geldige code wint.
     */
    public function attachReferral(User $user, ?string $code): ?PartnerReferral
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }

        $partner = Partner::where('referral_code', $code)
            ->where('status', Partner::STATUS_APPROVED)
            ->first();

        // Alleen partners met een geaccepteerde + bevestigde overeenkomst
        // mogen referrals ontvangen.
        if (! $partner || ! $partner->agreementComplete() || $partner->user_id === $user->id) {
            return null;
        }

        try {
            return PartnerReferral::firstOrCreate(
                ['referred_user_id' => $user->id],
                [
                    'partner_id'               => $partner->id,
                    'referral_code'            => $partner->referral_code,
                    'discount_applied'         => $partner->discount_rate,
                    'commission_rate_snapshot' => $partner->commission_rate,
                    'converted_at'             => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[partner] referral koppelen mislukt', [
                'user' => $user->id, 'code' => $code, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Pas de partnerkorting toe op Checkout-sessieparams als deze gebruiker via
     * een partner is binnengekomen en er niet al een andere korting op de sessie
     * staat. Stripe staat 'discounts' en 'allow_promotion_codes' niet samen toe.
     */
    public function applyReferralDiscount(array $sessionParams, ?User $user): array
    {
        if (! $user || isset($sessionParams['discounts'])) {
            return $sessionParams;
        }

        $referral = PartnerReferral::where('referred_user_id', $user->id)->first();
        if (! $referral || $referral->discount_applied <= 0) {
            return $sessionParams;
        }

        try {
            $couponId = $this->discountCouponId((float) $referral->discount_applied);
            $sessionParams['discounts'] = [['coupon' => $couponId]];
            unset($sessionParams['allow_promotion_codes']);
        } catch (\Throwable $e) {
            // Best-effort: zonder korting afrekenen is beter dan een kapotte checkout.
            Log::warning('[partner] kortingscoupon toepassen mislukt', [
                'user' => $user->id, 'error' => $e->getMessage(),
            ]);
        }

        return $sessionParams;
    }

    /**
     * Idempotente Stripe-coupon per kortingspercentage. De korting geldt de
     * eerste 12 maanden (repeating): bij een maandabonnement de eerste 12
     * termijnen, bij een jaarabonnement dus alleen de eerste jaarfactuur —
     * hetzelfde venster als de partnercommissie (COMMISSION_WINDOW_MONTHS).
     * Nieuwe id-namespace (milmap-partner12-…) zodat de oude forever-coupons
     * in Stripe onaangeroerd blijven.
     */
    public function discountCouponId(float $percentOff): string
    {
        $percentOff = round($percentOff, 2);
        // Stripe-coupon-ids mogen geen punt bevatten; 12.5 → milmap-partner12-12_5
        $couponId = 'milmap-partner12-' . str_replace('.', '_', rtrim(rtrim(number_format($percentOff, 2, '.', ''), '0'), '.'));

        try {
            $this->stripe->coupons->retrieve($couponId);

            return $couponId;
        } catch (\Throwable $e) {
            // Bestaat nog niet → aanmaken.
        }

        $this->stripe->coupons->create([
            'id'                 => $couponId,
            'percent_off'        => $percentOff,
            'duration'           => 'repeating',
            'duration_in_months' => self::COMMISSION_WINDOW_MONTHS,
            'name'               => "MilMap Partnerkorting {$percentOff}% (eerste 12 mnd)",
        ]);

        return $couponId;
    }

    /**
     * Guest-checkout-variant van applyReferralDiscount: er is nog geen user
     * (het account ontstaat pas in de webhook), dus de korting komt direct
     * van de partner achter de meegegeven referral-code. Zelfde regels als
     * attachReferral: partner moet approved zijn met complete overeenkomst.
     */
    public function applyCodeDiscount(array $sessionParams, ?string $code): array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || isset($sessionParams['discounts'])) {
            return $sessionParams;
        }

        $partner = Partner::where('referral_code', $code)
            ->where('status', Partner::STATUS_APPROVED)
            ->first();
        if (! $partner || ! $partner->agreementComplete() || $partner->discount_rate <= 0) {
            return $sessionParams;
        }

        try {
            $couponId = $this->discountCouponId((float) $partner->discount_rate);
            $sessionParams['discounts'] = [['coupon' => $couponId]];
            // Stripe staat 'discounts' en 'allow_promotion_codes' niet samen toe.
            unset($sessionParams['allow_promotion_codes']);
        } catch (\Throwable $e) {
            Log::warning('[partner] guest-kortingscoupon toepassen mislukt', [
                'code' => $code, 'error' => $e->getMessage(),
            ]);
        }

        return $sessionParams;
    }

    /**
     * Registreer een pending commissie voor een betaalde Stripe-factuur van een
     * doorverwezen gebruiker. Idempotent op stripe_invoice_id, dus een webhook-
     * retry maakt nooit een dubbele commissie aan.
     */
    public function recordCommission(object $invoice): ?PartnerCommission
    {
        $customerId = is_string($invoice->customer ?? null)
            ? $invoice->customer
            : ($invoice->customer->id ?? null);

        $amountPaid = (int) ($invoice->amount_paid ?? 0);
        if (! $customerId || $amountPaid <= 0) {
            return null;
        }

        $user = User::where('stripe_id', $customerId)->first();
        if (! $user) {
            return null;
        }

        $referral = PartnerReferral::where('referred_user_id', $user->id)->first();
        if (! $referral) {
            return null;
        }

        // Eerste-jaar-venster: de eerste commissie voor deze referral markeert
        // de eerste betaling; alles ná 12 maanden daarna telt niet meer mee
        // (ook niet als die eerste commissie inmiddels is terugbetaald).
        $firstCommission = PartnerCommission::where('partner_referral_id', $referral->id)
            ->orderBy('created_at')
            ->first();
        if ($firstCommission
            && $firstCommission->created_at->addMonths(self::COMMISSION_WINDOW_MONTHS)->isPast()
        ) {
            return null;
        }

        $gross      = round($amountPaid / 100, 2);
        $commission = round($gross * ($referral->commission_rate_snapshot / 100), 2);
        if ($commission <= 0) {
            return null;
        }

        $record = PartnerCommission::firstOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'partner_id'               => $referral->partner_id,
                'partner_referral_id'      => $referral->id,
                'stripe_payment_intent_id' => is_string($invoice->payment_intent ?? null)
                    ? $invoice->payment_intent
                    : ($invoice->payment_intent->id ?? null),
                'gross_amount'             => $gross,
                'commission_amount'        => $commission,
                'status'                   => PartnerCommission::STATUS_PENDING,
            ]
        );

        if ($record->wasRecentlyCreated) {
            Log::info('[partner] commissie geregistreerd', [
                'partner'    => $referral->partner_id,
                'user'       => $user->id,
                'invoice'    => $invoice->id,
                'commission' => $commission,
            ]);
        }

        return $record;
    }

    /**
     * Refund op een factuur waarvoor een commissie stond: markeer de commissie
     * als refunded zolang hij nog niet is uitbetaald. Al uitbetaalde commissies
     * laten we staan (handmatig verrekenen); we loggen dat als waarschuwing.
     */
    public function markRefunded(string $stripeInvoiceId): void
    {
        $commission = PartnerCommission::where('stripe_invoice_id', $stripeInvoiceId)->first();
        if (! $commission) {
            return;
        }

        if ($commission->status === PartnerCommission::STATUS_PENDING) {
            $commission->update(['status' => PartnerCommission::STATUS_REFUNDED]);
        } elseif ($commission->status === PartnerCommission::STATUS_PAID) {
            Log::warning('[partner] refund op al uitbetaalde commissie — handmatig verrekenen', [
                'commission' => $commission->id,
                'invoice'    => $stripeInvoiceId,
            ]);
        }
    }
}
