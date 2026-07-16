<?php

namespace App\Services;

use App\Models\AppsumoCode;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * AppSumo lifetime-deal verzilvering.
 *
 * Een koper heeft al betaald via AppSumo. Bij het verzilveren van de code krijgt
 * hij een MilMap-account + een Stripe-abonnement op het AppSumo-product met een
 * 100%-korting-coupon (duration=forever), zodat `User::subscribed()` true wordt
 * en alle premiumfuncties opengaan zonder dat er ooit iets afgeschreven wordt.
 *
 * De 100%-coupon betekent dat Stripe geen betaalmethode vraagt: het abonnement
 * wordt direct actief aangemaakt (geen Checkout-sessie nodig).
 */
class AppsumoService
{
    /** Idempotente coupon-id voor 100% korting op het AppSumo-abonnement. */
    public const COUPON_ID = 'milmap-appsumo-100';

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
     * Zoek een AppSumo-code op en geef 'm terug als hij nog verzilverd kan worden.
     * Geeft null bij onbekende of al verzilverde codes.
     */
    public function findRedeemable(?string $code): ?AppsumoCode
    {
        $code = AppsumoCode::normalize($code);
        if ($code === '') {
            return null;
        }

        $row = AppsumoCode::where('code', $code)->first();
        if (! $row || ! $row->isAvailable()) {
            return null;
        }

        return $row;
    }

    /**
     * Verzilver een code voor een (net aangemaakte) gebruiker: zet het
     * 100%-korting-abonnement in Stripe, spiegel het lokaal en markeer de code
     * als verzilverd. Gooit een RuntimeException bij een Stripe-fout zodat de
     * aanroeper de transactie kan terugdraaien.
     */
    public function redeem(AppsumoCode $code, User $user): AppsumoCode
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Stripe is niet geconfigureerd.');
        }

        // 1. Zorg voor een Stripe-customer.
        $customerId = $this->ensureStripeCustomer($user);

        // 2. Resolve de Price van het AppSumo-product + de 100%-coupon.
        $priceId  = $this->resolvePriceId();
        $couponId = $this->ensureCoupon();

        // 3. Maak het abonnement aan. 100% korting → geen betaalmethode nodig.
        $sub = $this->stripe->subscriptions->create([
            'customer' => $customerId,
            'items'    => [['price' => $priceId]],
            'discounts' => [['coupon' => $couponId]],
            'payment_behavior' => 'allow_incomplete',
            'metadata' => [
                'user_id'      => $user->id,
                'source'       => 'appsumo',
                'appsumo_code' => $code->code,
            ],
            'expand' => ['items.data.price'],
        ]);

        // 4. Spiegel het abonnement lokaal (zelfde vorm als de webhook doet), zodat
        //    User::activeSubscription() meteen werkt zonder op de webhook te wachten.
        $this->mirrorSubscription($sub, $user);

        // 5. Betaald account → e-mail als geverifieerd markeren (geen verificatie-wall).
        if (in_array($sub->status, ['active', 'trialing'], true)) {
            $user->markEmailVerified();
        }

        // 6. Code verbranden.
        $code->forceFill([
            'status'                 => AppsumoCode::STATUS_REDEEMED,
            'redeemed_by_user_id'    => $user->id,
            'stripe_subscription_id' => $sub->id,
            'redeemed_at'            => now(),
        ])->save();

        Log::info('[appsumo] code verzilverd', [
            'code' => $code->code, 'user' => $user->id, 'sub' => $sub->id, 'status' => $sub->status,
        ]);

        return $code;
    }

    /**
     * Stripe-customer id voor de gebruiker (maakt 'm aan als die er nog niet is).
     */
    private function ensureStripeCustomer(User $user): string
    {
        if ($user->stripe_id) {
            return $user->stripe_id;
        }

        $customer = $this->stripe->customers->create([
            'email'    => $user->email,
            'name'     => trim("{$user->first_name} {$user->last_name}"),
            'metadata' => ['user_id' => $user->id, 'signup' => 'appsumo'],
        ]);
        $user->forceFill(['stripe_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * De Price die bij het AppSumo-lifetime-product hoort. Volgorde:
     *   1. de door de admin gekoppelde prijs (Facturatie-scherm, slot 'lifetime');
     *   2. STRIPE_APPSUMO_PRICE uit .env;
     *   3. het default_price van het geconfigureerde product, anders de eerste
     *      actieve recurring price van dat product.
     */
    private function resolvePriceId(): string
    {
        $mapped = \App\Http\Controllers\AdminBillingController::effectiveMap()['lifetime'] ?? null;
        if ($mapped) {
            return $mapped;
        }

        if ($explicit = config('billing.appsumo_price')) {
            return $explicit;
        }

        $productId = config('billing.appsumo_product');
        $product   = $this->stripe->products->retrieve($productId, []);

        $default = is_string($product->default_price ?? null)
            ? $product->default_price
            : ($product->default_price->id ?? null);
        if ($default) {
            return $default;
        }

        $prices = $this->stripe->prices->all([
            'product' => $productId, 'active' => true, 'limit' => 1,
        ]);
        $priceId = $prices->data[0]->id ?? null;

        if (! $priceId) {
            throw new \RuntimeException("Geen actieve Price gevonden voor product {$productId}.");
        }

        return $priceId;
    }

    /**
     * Idempotente 100%-korting-coupon (duration=forever).
     */
    private function ensureCoupon(): string
    {
        try {
            $this->stripe->coupons->retrieve(self::COUPON_ID);

            return self::COUPON_ID;
        } catch (\Throwable $e) {
            // Bestaat nog niet → aanmaken.
        }

        $this->stripe->coupons->create([
            'id'          => self::COUPON_ID,
            'percent_off' => 100,
            'duration'    => 'forever',
            'name'        => 'MilMap AppSumo Lifetime (100%)',
        ]);

        return self::COUPON_ID;
    }

    /**
     * Spiegelt een Stripe-abonnement naar de lokale subscriptions/items-tabellen.
     * Zelfde upsert-vorm als BillingController::onCheckoutCompleted().
     */
    private function mirrorSubscription(object $stripeSub, User $user): void
    {
        $priceId = $stripeSub->items->data[0]->price->id ?? null;

        $sub = Subscription::updateOrCreate(
            ['stripe_id' => $stripeSub->id],
            [
                'user_id'       => $user->id,
                'type'          => 'default',
                'stripe_status' => $stripeSub->status,
                'stripe_price'  => $priceId,
                'quantity'      => $stripeSub->items->data[0]->quantity ?? 1,
                'trial_ends_at' => $stripeSub->trial_end
                    ? \Carbon\Carbon::createFromTimestamp($stripeSub->trial_end)
                    : null,
                'ends_at'       => null,
            ]
        );

        foreach ($stripeSub->items->data as $item) {
            SubscriptionItem::updateOrCreate(
                ['stripe_id' => $item->id],
                [
                    'subscription_id' => $sub->id,
                    'stripe_product'  => $item->price->product ?? null,
                    'stripe_price'    => $item->price->id,
                    'quantity'        => $item->quantity,
                ]
            );
        }
    }
}
