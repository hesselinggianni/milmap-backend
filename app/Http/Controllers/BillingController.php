<?php

namespace App\Http\Controllers;

use App\Mail\AccountActivationMail;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class BillingController extends Controller
{
    private StripeClient $stripe;

    // Cache key voor de live Stripe-prijzen. De cache wordt NIET op een timer
    // verlopen: hij blijft staan tot een admin nieuwe prijzen koppelt (savePriceMap)
    // of de cache handmatig leegt in de admin-omgeving. Zo hammeren we Stripe niet
    // en blijven de getoonde bedragen stabiel tot de beheerder ze bewust ververst.
    public const PRICING_CACHE_KEY = 'billing_pricing_v1';

    // ── Plan registry ──────────────────────────────────────────────
    // Map our plan keys to Stripe Price IDs. The admin can override the .env
    // defaults by selecting products in the admin UI (stored in settings);
    // AdminBillingController::effectiveMap() merges overrides over the defaults.
    private function plans(): array
    {
        return AdminBillingController::effectiveMap();
    }

    public function __construct()
    {
        // Geef de secret als api_key door, of null wanneer hij ontbreekt/leeg is.
        // StripeClient staat een null api_key toe (faalt pas bij een echte call),
        // maar gooit "api_key cannot be the empty string" bij een lege string en
        // "$config must be a string or an array" bij null als losse waarde.
        // Via ['api_key' => ... ?: null] werken alle gevallen, zodat ook publieke
        // endpoints (zoals pricing()) zonder geconfigureerde secret niet 500'en.
        $this->stripe = new StripeClient(['api_key' => config('billing.stripe_secret') ?: null]);
    }

    // ── Plan labels ────────────────────────────────────────────────
    private function planLabel(string $planKey): string
    {
        return match ($planKey) {
            'pro_monthly'  => 'Pro (maandelijks)',
            'pro_yearly'   => 'Pro (jaarlijks)',
            'team_monthly' => 'Team (maandelijks)',
            'team_yearly'  => 'Team (jaarlijks)',
            default        => 'Pro',
        };
    }

    // ── GET /billing/pricing ───────────────────────────────────────
    // Public — returns the live Stripe pricing for every MilMap plan slot so the
    // checkout/landing pages show the real amount + currency that Stripe will
    // actually charge (instead of a hard-coded number that can drift). Each plan
    // resolves to its mapped Stripe Price ID (admin override → .env default) and
    // we read the unit_amount/currency/interval plus the parent product id back
    // from Stripe. Cached briefly so a busy checkout page never hammers Stripe.
    public function pricing()
    {
        if (! config('billing.stripe_secret')) {
            // Stripe not configured → no live data; the client toont '…'.
            return response()->json(['plans' => (object) []]);
        }

        try {
            $plans = $this->cachedPricing();
        } catch (\Throwable $e) {
            Log::warning('billing.pricing: onverwachte fout', ['error' => $e->getMessage()]);

            return response()->json(['plans' => (object) []]);
        }

        return response()->json(['plans' => empty($plans) ? (object) [] : $plans]);
    }

    /**
     * De gecachete Stripe-prijzen per plan-slot. De cache verloopt NIET vanzelf
     * (geen TTL): hij blijft staan tot een admin nieuwe prijzen koppelt of de
     * cache handmatig leegt. Een mislukte/lege ophaalpoging wordt bewust NIET
     * gecachet, zodat een tijdelijke Stripe-storing nooit een lege prijslijst
     * "vastzet" tot de eerstvolgende handmatige flush.
     *
     * @return array<string, array<string, mixed>>  [planKey => priceData]
     */
    private function cachedPricing(): array
    {
        $cached = Cache::get(self::PRICING_CACHE_KEY);
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $fresh = $this->buildPricing();
        if (! empty($fresh)) {
            Cache::forever(self::PRICING_CACHE_KEY, $fresh);
        }

        return $fresh;
    }

    /**
     * Haal de actuele prijzen rechtstreeks bij Stripe op voor elke plan-slot uit
     * de admin-mapping (override → .env default). Dit is de enige plek die Stripe
     * raakt; alle endpoints lezen via cachedPricing() zodat we Stripe niet
     * onnodig bevragen.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPricing(): array
    {
        $map = AdminBillingController::effectiveMap();
        $out = [];

        foreach ($map as $key => $priceId) {
            if (! $priceId) {
                continue;
            }

            try {
                $price = $this->stripe->prices->retrieve($priceId, ['expand' => ['product']]);
            } catch (\Throwable $e) {
                // A single broken/deleted price must not nuke the whole response.
                Log::warning('billing.pricing: kon price niet laden', [
                    'plan'  => $key,
                    'price' => $priceId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $product = $price->product ?? null;

            $out[$key] = [
                'plan'         => $key,
                'price_id'     => $price->id,
                'product_id'   => is_object($product)
                    ? ($product->id ?? null)
                    : (is_string($product) ? $product : null),
                'product_name' => is_object($product) ? ($product->name ?? '') : '',
                'amount'       => $price->unit_amount,                 // in cents
                'currency'     => strtoupper((string) $price->currency),
                'interval'     => $price->recurring->interval ?? null, // month | year | null
            ];
        }

        return $out;
    }

    /**
     * Leeg de prijscache. Aangeroepen wanneer een admin nieuwe prijzen koppelt
     * (AdminBillingController::savePriceMap) of de cache handmatig ververst.
     */
    public static function forgetPricingCache(): void
    {
        Cache::forget(self::PRICING_CACHE_KEY);
    }

    // ── POST /billing/guest-checkout ───────────────────────────────
    // Public — geen auth vereist. Maakt account aan als het nog niet bestaat.
    public function guestCheckout(Request $request)
    {
        $request->validate([
            'email'      => 'required|email|max:255',
            'plan'       => 'required|string|in:pro_monthly,pro_yearly,team_monthly,team_yearly',
            'first_name' => 'nullable|string|max:100',
            'last_name'  => 'nullable|string|max:100',
        ]);

        $plans   = $this->plans();
        $priceId = $plans[$request->plan] ?? null;

        if (! $priceId) {
            return response()->json(['message' => 'Ongeldig plan geselecteerd.'], 422);
        }

        // Look up the account only IF it already exists. For a brand-new e-mail
        // we deliberately do NOT create the MilMap account here — that happens
        // only after the payment succeeds (see onCheckoutCompleted), so an
        // abandoned or failed checkout never leaves an orphan account behind.
        $user      = User::where('email', $request->email)->first();
        $isNewUser = ! $user;

        // Stripe redirect points to app
        $appUrl = config('app.app_url', 'https://app.milmap.nl');

        try {
            if ($user) {
                // Existing account → reuse (or lazily create) its Stripe customer
                // and tag the session with the real user_id.
                if (! $user->stripe_id) {
                    $customer = $this->stripe->customers->create([
                        'email'    => $user->email,
                        'name'     => trim("{$user->first_name} {$user->last_name}"),
                        'metadata' => ['user_id' => $user->id],
                    ]);
                    $user->update(['stripe_id' => $customer->id]);
                }

                $customerParams = [
                    'customer'        => $user->stripe_id,
                    'customer_update' => ['address' => 'auto'],
                ];
                $meta = [
                    'user_id'     => (string) $user->id,
                    'plan'        => $request->plan,
                    'is_new_user' => 'false',
                ];
            } else {
                // New e-mail → let Checkout create the Stripe customer from the
                // entered address; the MilMap account itself is created in the
                // webhook once payment succeeds. The registration details ride
                // along in metadata so the webhook can build the account.
                $customerParams = ['customer_email' => $request->email];
                $meta = [
                    'plan'                 => $request->plan,
                    'is_new_user'          => 'true',
                    'pending_registration' => 'true',
                    'reg_email'            => $request->email,
                    'reg_first_name'       => (string) ($request->first_name ?? ''),
                    'reg_last_name'        => (string) ($request->last_name ?? ''),
                ];
            }

            // Create Checkout Session
            // NB: payment_method_types wordt bewust NIET hardcoded. Stripe Checkout
            // kiest dan automatisch de in het Dashboard geactiveerde methodes die
            // geldig zijn voor deze mode/valuta. Hardcoden van bv. 'ideal' geeft
            // anders "payment method type ... is invalid" als de combinatie niet klopt.
            $session = $this->stripe->checkout->sessions->create(array_merge($customerParams, [
                'mode'                 => 'subscription',
                'line_items'           => [[
                    'price'    => $priceId,
                    'quantity' => 1,
                ]],
                'subscription_data' => [
                    'metadata' => $meta,
                ],
                'success_url' => "{$appUrl}/billing/success?session_id={CHECKOUT_SESSION_ID}",
                'cancel_url'  => "{$appUrl}/checkout/{$request->plan}?cancelled=1",
                'metadata'    => $meta,
                'allow_promotion_codes'      => true,
                'billing_address_collection' => 'auto',
                'locale'                     => 'nl',
            ]));
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe rejected the request. The most common cause here is a Price
            // ID that doesn't exist in the account/mode the active secret key
            // points to (a test-vs-live mismatch) → "No such price: price_…".
            // Surface a clean message and log the real reason + the offending
            // price so this never returns an opaque 500 again.
            Log::error('guestCheckout Stripe error', [
                'plan'         => $request->plan,
                'price_id'     => $priceId,
                'email'        => $request->email,
                'stripe_code'  => method_exists($e, 'getStripeCode') ? $e->getStripeCode() : null,
                'stripe_error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Afrekenen is op dit moment niet beschikbaar. Probeer het later opnieuw of neem contact op met support.',
            ], 502);
        }

        return response()->json([
            'checkout_url' => $session->url,
            'session_id'   => $session->id,
            'is_new_user'  => $isNewUser,
        ]);
    }

    // ── POST /billing/check-email ──────────────────────────────────
    // Public — used by the checkout page to detect an existing account before
    // starting a guest checkout. If the e-mail already belongs to a user, the
    // frontend redirects them to log in and up-/downgrade instead of creating a
    // duplicate subscription. Rate-limited at the route to blunt enumeration.
    public function checkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email|max:255']);

        $user = User::where('email', $request->email)->first();

        return response()->json([
            'exists'           => (bool) $user,
            'has_subscription' => $user ? $user->subscribed() : false,
        ]);
    }

    // ── GET /billing/subscription ──────────────────────────────────
    public function subscription(Request $request)
    {
        $user = $request->user();

        // Reconcile with Stripe so a subscription created or changed directly in
        // Stripe (or after a missed webhook) is still reflected in the app.
        $stripeSub = $this->syncFromStripe($user);
        $sub = $user->fresh()->activeSubscription();

        if (! $sub) {
            return response()->json([
                'subscribed' => false,
                'plan'       => 'starter',
            ]);
        }

        $user->refresh();

        return response()->json([
            'subscribed'           => true,
            'plan'                 => $user->plan(),
            'plan_key'             => User::planKeyForPrice($sub->stripe_price),
            'status'               => $sub->stripe_status,
            'stripe_price'         => $sub->stripe_price,
            'ends_at'              => $sub->ends_at?->toISOString(),
            'trial_ends_at'        => $sub->trial_ends_at?->toISOString(),
            'canceled'             => $sub->canceled() || (bool) ($stripeSub->cancel_at_period_end ?? false),
            'cancel_at_period_end' => (bool) ($stripeSub->cancel_at_period_end ?? false),
            'current_period_end'   => isset($stripeSub->current_period_end)
                ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end)->toISOString()
                : null,
        ]);
    }

    /**
     * Pull the customer's subscriptions from Stripe and upsert them locally so
     * the app's view always matches Stripe. Returns the primary (active/trialing)
     * Stripe subscription object, or null.
     */
    private function syncFromStripe($user)
    {
        if (! $user->stripe_id) {
            return null;
        }

        try {
            $subs = $this->stripe->subscriptions->all([
                'customer' => $user->stripe_id,
                'status'   => 'all',
                'limit'    => 10,
                'expand'   => ['data.items.data.price'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stripe-synchronisatie mislukt', ['user' => $user->id, 'error' => $e->getMessage()]);
            return null;
        }

        $primary = null;
        foreach ($subs->data as $s) {
            $priceId = $s->items->data[0]->price->id ?? null;
            $endsAt  = null;
            if (! empty($s->cancel_at)) {
                $endsAt = \Carbon\Carbon::createFromTimestamp($s->cancel_at);
            } elseif ($s->status === 'canceled' && ! empty($s->ended_at)) {
                $endsAt = \Carbon\Carbon::createFromTimestamp($s->ended_at);
            }

            Subscription::updateOrCreate(
                ['stripe_id' => $s->id],
                [
                    'user_id'       => $user->id,
                    'type'          => 'default',
                    'stripe_status' => $s->status,
                    'stripe_price'  => $priceId,
                    'quantity'      => $s->items->data[0]->quantity ?? 1,
                    'trial_ends_at' => ! empty($s->trial_end) ? \Carbon\Carbon::createFromTimestamp($s->trial_end) : null,
                    'ends_at'       => $endsAt,
                ]
            );

            if (! $primary && in_array($s->status, ['active', 'trialing', 'past_due'], true)) {
                $primary = $s;
            }
        }

        return $primary ?: ($subs->data[0] ?? null);
    }

    // ── POST /billing/checkout ─────────────────────────────────────
    public function createCheckout(Request $request)
    {
        $request->validate([
            'plan' => 'required|string|in:pro_monthly,pro_yearly,team_monthly,team_yearly',
        ]);

        $user   = $request->user();
        $plans  = $this->plans();
        $priceId = $plans[$request->plan] ?? null;

        if (! $priceId) {
            return response()->json(['message' => 'Ongeldig plan geselecteerd.'], 422);
        }

        // Stripe redirect gaat naar de app (waar de user ingelogd is)
        $siteUrl = config('app.app_url', 'https://app.milmap.nl');

        // Create or retrieve Stripe customer
        if (! $user->stripe_id) {
            $customer = $this->stripe->customers->create([
                'email' => $user->email,
                'name'  => trim("{$user->first_name} {$user->last_name}"),
                'metadata' => ['user_id' => $user->id],
            ]);
            $user->update(['stripe_id' => $customer->id]);
        }

        // Create Checkout Session
        // NB: payment_method_types wordt bewust NIET hardcoded — zie guestCheckout().
        // Stripe Checkout bepaalt zelf welke geactiveerde methodes geldig zijn.
        $session = $this->stripe->checkout->sessions->create([
            'customer'             => $user->stripe_id,
            'mode'                 => 'subscription',
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->id,
                    'plan'    => $request->plan,
                ],
            ],
            'success_url' => "{$siteUrl}/billing/success?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url'  => "{$siteUrl}/billing/cancel",
            'metadata'    => [
                'user_id' => $user->id,
                'plan'    => $request->plan,
            ],
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
            'locale' => 'nl',
        ]);

        return response()->json([
            'checkout_url' => $session->url,
            'session_id'   => $session->id,
        ]);
    }

    // ── POST /billing/portal ───────────────────────────────────────
    public function createPortal(Request $request)
    {
        $user = $request->user();

        if (! $user->stripe_id) {
            return response()->json(['message' => 'Geen actief abonnement gevonden.'], 404);
        }

        $siteUrl = config('app.app_url', 'https://app.milmap.nl');

        $session = $this->stripe->billingPortal->sessions->create([
            'customer'   => $user->stripe_id,
            'return_url' => "{$siteUrl}/billing/success",
        ]);

        return response()->json(['portal_url' => $session->url]);
    }

    // ── POST /billing/cancel ───────────────────────────────────────
    // Cancel at period end via Stripe (the user keeps access until then).
    public function cancel(Request $request)
    {
        $user = $request->user();
        $this->syncFromStripe($user);
        $sub = $user->fresh()->activeSubscription();

        if (! $sub || ! $sub->stripe_id) {
            return response()->json(['message' => 'Geen actief abonnement gevonden.'], 404);
        }

        try {
            $stripeSub = $this->stripe->subscriptions->update($sub->stripe_id, [
                'cancel_at_period_end' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Opzeggen mislukt: ' . $e->getMessage()], 422);
        }

        $endsAt = isset($stripeSub->current_period_end)
            ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end)
            : now();
        $sub->update(['ends_at' => $endsAt]);

        return response()->json([
            'ok'      => true,
            'ends_at' => $endsAt->toISOString(),
            'message' => 'Abonnement opgezegd. Het blijft actief tot het einde van de huidige periode.',
        ]);
    }

    // ── POST /billing/resume ───────────────────────────────────────
    // Undo a pending cancellation.
    public function resume(Request $request)
    {
        $user = $request->user();
        $this->syncFromStripe($user);
        $sub = $user->fresh()->activeSubscription();

        if (! $sub || ! $sub->stripe_id) {
            return response()->json(['message' => 'Geen abonnement gevonden.'], 404);
        }

        try {
            $this->stripe->subscriptions->update($sub->stripe_id, [
                'cancel_at_period_end' => false,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Hervatten mislukt: ' . $e->getMessage()], 422);
        }

        $sub->update(['ends_at' => null]);

        return response()->json(['ok' => true, 'message' => 'Abonnement hervat.']);
    }

    // ── POST /billing/change-plan ──────────────────────────────────
    // Up-/downgrade an existing subscription. The new plan takes effect
    // immediately; Stripe proration ('create_prorations') credits the unused
    // portion of the old plan against the new one and settles the difference on
    // the next invoice — so the customer is never double-charged.
    public function changePlan(Request $request)
    {
        $request->validate([
            'plan' => 'required|string|in:pro_monthly,pro_yearly,team_monthly,team_yearly',
        ]);

        $user    = $request->user();
        $priceId = $this->plans()[$request->plan] ?? null;

        if (! $priceId) {
            return response()->json(['message' => 'Ongeldig plan geselecteerd.'], 422);
        }

        // Reconcile first so we act on the real current subscription.
        $this->syncFromStripe($user);
        $sub = $user->fresh()->activeSubscription();

        if (! $sub || ! $sub->stripe_id) {
            return response()->json([
                'message' => 'Geen actief abonnement gevonden. Sluit eerst een abonnement af.',
            ], 404);
        }

        if ($sub->stripe_price === $priceId) {
            return response()->json(['message' => 'Je hebt dit plan al.', 'already' => true], 422);
        }

        try {
            $stripeSub = $this->stripe->subscriptions->retrieve($sub->stripe_id, [
                'expand' => ['items.data.price'],
            ]);
            $itemId = $stripeSub->items->data[0]->id ?? null;

            if (! $itemId) {
                return response()->json(['message' => 'Abonnementsregel niet gevonden.'], 422);
            }

            $updated = $this->stripe->subscriptions->update($sub->stripe_id, [
                'items' => [[
                    'id'    => $itemId,
                    'price' => $priceId,
                ]],
                // Invoice the proration immediately so an upgrade charges the
                // difference right away (and a downgrade credits the difference).
                'proration_behavior'   => 'always_invoice',
                'payment_behavior'     => 'error_if_incomplete',
                'cancel_at_period_end' => false,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Wijzigen mislukt: ' . $e->getMessage()], 422);
        }

        $sub->update([
            'stripe_status' => $updated->status,
            'stripe_price'  => $priceId,
            'ends_at'       => null,
        ]);

        return response()->json([
            'ok'       => true,
            'plan'     => $user->fresh()->plan(),
            'plan_key' => $request->plan,
            'status'   => $updated->status,
            'message'  => 'Abonnement gewijzigd. De wijziging gaat direct in en '
                . 'het prijsverschil is meteen verrekend.',
        ]);
    }

    // ── GET /billing/invoices ──────────────────────────────────────
    // The customer's invoices straight from Stripe.
    public function invoices(Request $request)
    {
        $user = $request->user();
        if (! $user->stripe_id) {
            return response()->json(['invoices' => []]);
        }

        try {
            $list = $this->stripe->invoices->all([
                'customer' => $user->stripe_id,
                'limit'    => 24,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['invoices' => [], 'error' => 'Facturen konden niet geladen worden.']);
        }

        $out = [];
        foreach ($list->data as $inv) {
            $out[] = [
                'id'                 => $inv->id,
                'number'             => $inv->number,
                'created'            => ! empty($inv->created)
                    ? \Carbon\Carbon::createFromTimestamp($inv->created)->toIso8601String() : null,
                'amount'             => $inv->amount_paid ?? $inv->total ?? 0, // cents
                'currency'           => strtoupper($inv->currency ?? 'eur'),
                'status'             => $inv->status,
                'hosted_invoice_url' => $inv->hosted_invoice_url ?? null,
                'invoice_pdf'        => $inv->invoice_pdf ?? null,
            ];
        }

        return response()->json(['invoices' => $out]);
    }

    // ── GET /billing/plans ─────────────────────────────────────────
    // Selectable plans with LIVE prices from Stripe (never hardcoded).
    public function availablePlans()
    {
        $names = [
            'pro_monthly'  => 'Pro',
            'pro_yearly'   => 'Pro',
            'team_monthly' => 'Team',
            'team_yearly'  => 'Team',
        ];

        if (! config('billing.stripe_secret')) {
            return response()->json(['plans' => []]);
        }

        try {
            // Lees uit dezelfde gecachete bron als /billing/pricing zodat de
            // bedragen consistent zijn en Stripe niet per request wordt bevraagd.
            $pricing = $this->cachedPricing();
        } catch (\Throwable $e) {
            Log::warning('billing.availablePlans: prijzen ophalen mislukt', ['error' => $e->getMessage()]);

            return response()->json(['plans' => []]);
        }

        $out = [];
        foreach ($pricing as $key => $p) {
            $out[] = [
                'key'      => $key,
                'name'     => $p['product_name'] ?: ($names[$key] ?? 'Plan'),
                'price_id' => $p['price_id'],
                'amount'   => $p['amount'],                 // cents
                'currency' => $p['currency'] ?: 'EUR',
                'interval' => $p['interval'],               // month | year
            ];
        }

        return response()->json(['plans' => $out]);
    }

    // ── GET /billing/session ───────────────────────────────────────
    // Verify and return details of a completed checkout session
    public function verifySession(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);

        $user    = $request->user();
        $session = $this->stripe->checkout->sessions->retrieve(
            $request->session_id,
            ['expand' => ['subscription', 'customer']]
        );

        // Security check — make sure this session belongs to this user
        if ($session->metadata->user_id != $user->id) {
            return response()->json(['message' => 'Ongeautoriseerd.'], 403);
        }

        $sub = $session->subscription;

        return response()->json([
            'status'       => $session->payment_status,
            'plan'         => $session->metadata->plan ?? null,
            'customer_email' => $session->customer?->email ?? $user->email,
            'subscription_status' => $sub?->status ?? null,
            'current_period_end'  => $sub ? date('Y-m-d', $sub->current_period_end) : null,
        ]);
    }

    // ── POST /billing/webhook ──────────────────────────────────────
    // Public endpoint — verified via Stripe signature
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sig     = $request->header('Stripe-Signature');
        $secret  = config('billing.stripe_webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature mismatch', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook parse error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Bad payload'], 400);
        }

        Log::info('Stripe webhook received', ['type' => $event->type]);

        match ($event->type) {
            'checkout.session.completed'     => $this->onCheckoutCompleted($event->data->object),
            'customer.subscription.updated'  => $this->onSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted'  => $this->onSubscriptionDeleted($event->data->object),
            'invoice.payment_failed'         => $this->onPaymentFailed($event->data->object),
            default                          => null,
        };

        return response()->json(['received' => true]);
    }

    // ── Webhook handlers ───────────────────────────────────────────

    private function onCheckoutCompleted(object $session): void
    {
        $userId    = $session->metadata->user_id ?? null;
        $isNewUser = ($session->metadata->is_new_user ?? 'false') === 'true';
        $planKey   = $session->metadata->plan ?? 'pro_monthly';
        $pending   = ($session->metadata->pending_registration ?? 'false') === 'true';

        // Gast-checkout: het MilMap-account wordt PAS aangemaakt nadat de betaling
        // is geslaagd (deze webhook). Zo blijven er geen weesaccounts achter bij
        // afgebroken of mislukte betalingen.
        if (! $userId && $pending) {
            $email = $session->metadata->reg_email
                ?? ($session->customer_details->email ?? null);

            if (! $email) {
                Log::warning('Pending registration zonder e-mailadres', [
                    'session' => $session->id ?? null,
                ]);
                return;
            }

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $session->metadata->reg_first_name ?? '',
                    'last_name'  => $session->metadata->reg_last_name ?? '',
                    'password'   => Hash::make(Str::random(32)),
                ]
            );

            // Koppel de Stripe-customer aan het nieuwe account.
            $customerId = is_string($session->customer ?? null)
                ? $session->customer
                : ($session->customer->id ?? null);
            if ($customerId && ! $user->stripe_id) {
                $user->update(['stripe_id' => $customerId]);
            }

            $userId    = $user->id;
            // Alleen een activatiemail sturen als het account nu net is aangemaakt.
            $isNewUser = $user->wasRecentlyCreated;
        }

        if (! $userId) return;

        $stripeSubId = $session->subscription ?? null;
        if (! $stripeSubId) return;

        // Fetch full subscription object
        $stripeSub = $this->stripe->subscriptions->retrieve($stripeSubId, [
            'expand' => ['items.data.price'],
        ]);

        $priceId = $stripeSub->items->data[0]->price->id ?? null;

        $sub = Subscription::updateOrCreate(
            ['stripe_id' => $stripeSubId],
            [
                'user_id'       => $userId,
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

        // Store subscription items
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

        Log::info('Subscription created for user', ['user_id' => $userId, 'stripe_sub' => $stripeSubId]);

        // Stuur activatiemail als het een nieuw account betreft
        if ($isNewUser) {
            $this->sendActivationEmail($userId, $planKey);
        }
    }

    private function sendActivationEmail(int $userId, string $planKey): void
    {
        $user = User::find($userId);
        if (! $user) return;

        try {
            // Maak een password reset token aan (hergebruik bestaande flow)
            $token = Password::createToken($user);

            $appUrl    = config('app.app_url', 'https://app.milmap.nl');
            $setupUrl  = "{$appUrl}/password-reset?token={$token}&email=" . urlencode($user->email);
            $planLabel = $this->planLabel($planKey);

            Mail::to($user->email)->send(
                new AccountActivationMail($setupUrl, $planLabel, $user->first_name)
            );

            Log::info('Activation email sent', ['user_id' => $userId, 'email' => $user->email]);
        } catch (\Exception $e) {
            Log::error('Failed to send activation email', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function onSubscriptionUpdated(object $stripeSub): void
    {
        $sub = Subscription::where('stripe_id', $stripeSub->id)->first();
        if (! $sub) return;

        $priceId = $stripeSub->items->data[0]->price->id ?? $sub->stripe_price;

        $sub->update([
            'stripe_status' => $stripeSub->status,
            'stripe_price'  => $priceId,
            'ends_at'       => $stripeSub->cancel_at
                ? \Carbon\Carbon::createFromTimestamp($stripeSub->cancel_at)
                : null,
            'trial_ends_at' => $stripeSub->trial_end
                ? \Carbon\Carbon::createFromTimestamp($stripeSub->trial_end)
                : null,
        ]);
    }

    private function onSubscriptionDeleted(object $stripeSub): void
    {
        $sub = Subscription::where('stripe_id', $stripeSub->id)->first();
        if (! $sub) return;

        $sub->update([
            'stripe_status' => 'canceled',
            'ends_at'       => now(),
        ]);
    }

    private function onPaymentFailed(object $invoice): void
    {
        $customerId = $invoice->customer ?? null;
        if (! $customerId) return;

        // Find active subscription for this customer and mark as past_due
        Subscription::whereHas('user', function ($q) use ($customerId) {
            $q->where('stripe_id', $customerId);
        })->update(['stripe_status' => 'past_due']);

        Log::warning('Payment failed for customer', ['stripe_customer' => $customerId]);
    }
}
