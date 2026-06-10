<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Throwable;

/**
 * Admin-only Stripe configuration: list the live products/prices straight from
 * the connected Stripe account and let an admin map each MilMap plan slot
 * (pro_monthly, …) to a Stripe Price ID. The chosen mapping is stored in the
 * settings table and overrides the .env defaults at checkout time.
 */
class AdminBillingController extends Controller
{
    public const PLAN_KEYS = ['pro_monthly', 'pro_yearly', 'team_monthly', 'team_yearly'];
    public const SETTING_KEY = 'billing_prices';

    // NB: bewust GEEN StripeClient in de constructor. Als de Stripe-secret
    // ontbreekt (of de config-cache stale is) gooit `new StripeClient(null)`
    // een InvalidArgumentException. Omdat de constructor bij ELKE actie draait,
    // crashte daardoor ook price-map / save-price-map met een 500 — terwijl die
    // Stripe helemaal niet nodig hebben. De client wordt nu pas in prices()
    // aangemaakt, ná de secret-check en binnen een try/catch.

    /**
     * The .env-configured defaults for each plan slot.
     */
    public static function defaultMap(): array
    {
        return [
            'pro_monthly'  => config('billing.stripe_price_pro_monthly'),
            'pro_yearly'   => config('billing.stripe_price_pro_yearly'),
            'team_monthly' => config('billing.stripe_price_team_monthly'),
            'team_yearly'  => config('billing.stripe_price_team_yearly'),
        ];
    }

    /**
     * The effective mapping: stored overrides on top of the .env defaults.
     */
    public static function effectiveMap(): array
    {
        $map = self::defaultMap();
        $overrides = Setting::get(self::SETTING_KEY, []);
        if (is_array($overrides)) {
            foreach (self::PLAN_KEYS as $k) {
                if (! empty($overrides[$k])) {
                    $map[$k] = $overrides[$k];
                }
            }
        }

        return $map;
    }

    /**
     * GET /api/v1/admin/billing/prices
     * All active recurring prices from Stripe, with their product info.
     */
    public function prices()
    {
        if (! config('billing.stripe_secret')) {
            return response()->json(['message' => 'Stripe is nog niet geconfigureerd (geen secret key).'], 422);
        }

        try {
            $stripe = new StripeClient(config('billing.stripe_secret'));
            $prices = $stripe->prices->all([
                'active' => true,
                'limit'  => 100,
                'expand' => ['data.product'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Kon Stripe-prijzen niet laden: ' . $e->getMessage(),
            ], 422);
        }

        $out = [];
        foreach ($prices->data as $p) {
            $product = $p->product ?? null;
            $out[] = [
                'id'           => $p->id,
                'product_id'   => is_object($product) ? ($product->id ?? null) : (is_string($product) ? $product : null),
                'product_name' => is_object($product) ? ($product->name ?? '') : '',
                'nickname'     => $p->nickname,
                'amount'       => $p->unit_amount,                 // in cents
                'currency'     => strtoupper((string) $p->currency),
                'interval'     => $p->recurring->interval ?? null, // month | year | null
                'type'         => $p->type,                        // recurring | one_time
            ];
        }

        // Recurring prices first, then by product name.
        usort($out, function ($a, $b) {
            if (($a['type'] === 'recurring') !== ($b['type'] === 'recurring')) {
                return $a['type'] === 'recurring' ? -1 : 1;
            }
            return strcmp($a['product_name'], $b['product_name']);
        });

        return response()->json(['prices' => $out]);
    }

    /**
     * GET /api/v1/admin/billing/price-map
     * The current plan → price mapping plus which slots come from overrides.
     */
    public function priceMap()
    {
        $overrides = Setting::get(self::SETTING_KEY, []);
        $overrides = is_array($overrides) ? $overrides : [];

        return response()->json([
            'map'      => self::effectiveMap(),
            'defaults' => self::defaultMap(),
            'overridden' => array_values(array_filter(
                self::PLAN_KEYS,
                fn ($k) => ! empty($overrides[$k])
            )),
        ]);
    }

    /**
     * PUT /api/v1/admin/billing/price-map
     * Persist the admin-selected Stripe Price IDs per plan slot.
     */
    public function savePriceMap(Request $request)
    {
        $data = $request->validate([
            'pro_monthly'  => ['nullable', 'string', 'max:255'],
            'pro_yearly'   => ['nullable', 'string', 'max:255'],
            'team_monthly' => ['nullable', 'string', 'max:255'],
            'team_yearly'  => ['nullable', 'string', 'max:255'],
        ]);

        // Keep only the known plan keys, drop empties.
        $clean = [];
        foreach (self::PLAN_KEYS as $k) {
            if (! empty($data[$k])) {
                $clean[$k] = $data[$k];
            }
        }

        Setting::put(self::SETTING_KEY, $clean);

        // Nieuwe koppeling gekozen → de live-prijscache is verouderd. Leeg hem
        // (via de centrale helper zodat de cache-key op één plek staat) zodat
        // checkout/account direct de zojuist gekozen prijzen tonen.
        BillingController::forgetPricingCache();

        return response()->json([
            'ok'  => true,
            'map' => self::effectiveMap(),
        ]);
    }

    /**
     * POST /api/v1/admin/billing/flush-cache
     * Leeg handmatig de live-prijscache. Handig wanneer een admin het bedrag van
     * een prijs in Stripe heeft aangepast zonder de koppeling te wijzigen — de
     * volgende aanvraag haalt de actuele bedragen dan opnieuw bij Stripe op.
     */
    public function flushPricingCache()
    {
        BillingController::forgetPricingCache();

        return response()->json(['ok' => true]);
    }
}
