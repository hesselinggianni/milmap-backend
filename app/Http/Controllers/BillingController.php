<?php

namespace App\Http\Controllers;

use App\Mail\AccountActivationMail;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use Illuminate\Http\Request;
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
        $this->stripe = new StripeClient(config('billing.stripe_secret'));
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

        // Find or create user
        $isNewUser = false;
        $user = User::where('email', $request->email)->first();

        if (! $user) {
            $isNewUser = true;
            $user = User::create([
                'email'      => $request->email,
                'first_name' => $request->first_name ?? '',
                'last_name'  => $request->last_name  ?? '',
                // Random password — will be set via activation email
                'password'   => Hash::make(Str::random(32)),
            ]);
        }

        // Stripe redirect points to app
        $appUrl = config('app.app_url', 'https://app.milmap.nl');

        // Create or retrieve Stripe customer
        if (! $user->stripe_id) {
            $customer = $this->stripe->customers->create([
                'email'    => $user->email,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'metadata' => ['user_id' => $user->id],
            ]);
            $user->update(['stripe_id' => $customer->id]);
        }

        // Create Checkout Session
        $session = $this->stripe->checkout->sessions->create([
            'customer'             => $user->stripe_id,
            'payment_method_types' => ['card', 'ideal'],
            'mode'                 => 'subscription',
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'customer_update' => ['address' => 'auto'],
            'subscription_data' => [
                'metadata' => [
                    'user_id'      => $user->id,
                    'plan'         => $request->plan,
                    'is_new_user'  => $isNewUser ? 'true' : 'false',
                ],
            ],
            'success_url' => "{$appUrl}/billing/success?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url'  => "{$appUrl}/checkout/{$request->plan}?cancelled=1",
            'metadata'    => [
                'user_id'     => $user->id,
                'plan'        => $request->plan,
                'is_new_user' => $isNewUser ? 'true' : 'false',
            ],
            'allow_promotion_codes'      => true,
            'billing_address_collection' => 'auto',
            'locale'                     => 'nl',
        ]);

        return response()->json([
            'checkout_url' => $session->url,
            'session_id'   => $session->id,
            'is_new_user'  => $isNewUser,
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
        $session = $this->stripe->checkout->sessions->create([
            'customer'             => $user->stripe_id,
            'payment_method_types' => ['card', 'ideal'],
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
