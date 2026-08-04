<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Stripe\StripeClient;

/**
 * Mint unieke, eenmalig bruikbare Stripe-promotiecodes voor de launch-campagne:
 * 20% korting op een jaarabonnement, geldig om in te wisselen tot 1 jaar na
 * uitgifte, en maar 1x te gebruiken.
 *
 * Eén gedeelde Stripe-coupon (20% off, duration=once, beperkt tot de
 * jaar-producten) wordt idempotent aangemaakt; per ontvanger genereren we een
 * losse promotion_code zodat elke code uniek + individueel intrekbaar is.
 */
class LaunchCouponService
{
    private StripeClient $stripe;

    /**
     * Vaste, herbruikbare coupon-id zodat we 'm niet elke keer opnieuw maken.
     * Nieuwe id (was 'milmap-launch-50-yearly') omdat Stripe's percent_off
     * immutable is op een bestaande coupon — de oude 50%-coupon blijft anders
     * stilzwijgend actief ook al staat hieronder 20%.
     */
    private const COUPON_ID = 'milmap-launch-20-yearly';

    /** Coupon-id voor de lead-nurture-code (20%, geldt op élk plan — niet jaar-only). */
    private const LEAD_NURTURE_COUPON_ID = 'milmap-lead-20-first';

    public function __construct()
    {
        $this->stripe = new StripeClient(['api_key' => config('billing.stripe_secret') ?: null]);
    }

    public function isConfigured(): bool
    {
        return (bool) config('billing.stripe_secret');
    }

    /**
     * Genereer één unieke promotiecode.
     *
     * @return array{code:string, expires_at:Carbon}
     */
    public function createYearlyDiscountCode(?string $email = null): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Stripe is niet geconfigureerd (billing.stripe_secret ontbreekt).');
        }

        $couponId  = $this->ensureCoupon();
        $expiresAt = Carbon::now()->addYear();

        $params = [
            // Sinds Stripe-API-versie met stripe-php v20 zit de coupon genest
            // onder 'promotion' i.p.v. als plat 'coupon'-veld (anders:
            // "Received unknown parameter: coupon").
            'promotion'       => ['coupon' => $couponId, 'type' => 'coupon'],
            'code'            => $this->generateCode(),
            'max_redemptions' => 1,                       // 1x te gebruiken
            'expires_at'      => $expiresAt->timestamp,   // in te wisselen binnen 1 jaar
        ];
        if ($email) {
            $params['metadata'] = ['email' => $email, 'campaign' => 'app-download-launch'];
        }

        $promo = $this->stripe->promotionCodes->create($params);

        return [
            'code'       => $promo->code,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Genereer één unieke promotiecode voor de lead-nurture-mail: 20% korting op
     * de eerste factuur (élk plan, niet beperkt tot jaar), met een kort
     * geldigheidsvenster ($validDays — de mail belooft "2 dagen geldig") en
     * max_redemptions=1 zodat 'm maar door één betaling verzilverd kan worden.
     *
     * @return array{code:string, expires_at:Carbon}
     */
    public function createLeadNurtureCode(?string $email = null, int $validDays = 2): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Stripe is niet geconfigureerd (billing.stripe_secret ontbreekt).');
        }

        $couponId  = $this->ensureLeadNurtureCoupon();
        $expiresAt = Carbon::now()->addDays($validDays);

        $params = [
            'promotion'       => ['coupon' => $couponId, 'type' => 'coupon'],
            'code'            => $this->generateCode(),
            'max_redemptions' => 1,
            'expires_at'      => $expiresAt->timestamp,
        ];
        if ($email) {
            $params['metadata'] = ['email' => $email, 'campaign' => 'lead-nurture-finish-account'];
        }

        $promo = $this->stripe->promotionCodes->create($params);

        return [
            'code'       => $promo->code,
            'expires_at' => $expiresAt,
        ];
    }

    /** Idempotent: 20% off, eenmalig (eerste factuur), geen product-beperking. */
    private function ensureLeadNurtureCoupon(): string
    {
        try {
            $this->stripe->coupons->retrieve(self::LEAD_NURTURE_COUPON_ID);
            return self::LEAD_NURTURE_COUPON_ID;
        } catch (\Throwable $e) {
            // Bestaat nog niet → aanmaken.
        }

        $this->stripe->coupons->create([
            'id'          => self::LEAD_NURTURE_COUPON_ID,
            'percent_off' => 20,
            'duration'    => 'once',
            // Stripe's coupon-'name' staat max. 40 tekens toe — de oorspronkelijke
            // naam ("MilMap — 20% op je eerste factuur (account afmaken)", 53
            // tekens) liet coupons->create() altijd falen, waardoor deze hele
            // lead-nurture-mail nooit verstuurd werd (stil gefaald, zie
            // LeadNurtureService::enroll()'s try/catch).
            'name'        => 'MilMap — 20% eerste factuur',
        ]);

        return self::LEAD_NURTURE_COUPON_ID;
    }

    /**
     * Zorg dat de gedeelde launch-coupon bestaat (idempotent). 20% off, eenmalig
     * toegepast (= eerste jaarfactuur), beperkt tot de jaar-producten als die in
     * de billing-config staan — anders een algemene 20%-eenmalig-coupon.
     */
    private function ensureCoupon(): string
    {
        try {
            $this->stripe->coupons->retrieve(self::COUPON_ID);
            return self::COUPON_ID;
        } catch (\Throwable $e) {
            // Bestaat nog niet → aanmaken.
        }

        $params = [
            'id'          => self::COUPON_ID,
            'percent_off' => 20,
            'duration'    => 'once',
            'name'        => 'MilMap Launch — 20% op je jaarabonnement',
        ];

        $products = $this->yearlyProductIds();
        if (! empty($products)) {
            $params['applies_to'] = ['products' => $products];
        }

        $this->stripe->coupons->create($params);

        return self::COUPON_ID;
    }

    /**
     * Resolve de Stripe-product-id's achter de jaar-prijzen, zodat de korting
     * alleen op een jaarabonnement geldt. Faalt stil per prijs.
     */
    private function yearlyProductIds(): array
    {
        $ids = [];
        foreach (['stripe_price_pro_yearly', 'stripe_price_team_yearly'] as $key) {
            $priceId = config('billing.' . $key);
            if (! $priceId) {
                continue;
            }
            try {
                $price = $this->stripe->prices->retrieve($priceId);
                $product = $price->product;
                $ids[] = is_string($product) ? $product : ($product->id ?? null);
            } catch (\Throwable $e) {
                // prijs niet vindbaar → overslaan
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Leesbare, ondubbelzinnige code zonder verwarrende tekens (0/O, 1/I).
     * Bijv. MILMAP-7KQ4-9XTR.
     */
    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $block = function (int $n) use ($alphabet): string {
            $out = '';
            for ($i = 0; $i < $n; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            return $out;
        };

        return 'MILMAP-' . $block(4) . '-' . $block(4);
    }
}
