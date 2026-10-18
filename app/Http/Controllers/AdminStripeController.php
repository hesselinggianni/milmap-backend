<?php

namespace App\Http\Controllers;

use App\Models\StripeError;
use App\Services\{StripeAdmin, StripeErrors};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Stripe in MilMap Admin: foutenoverzicht, facturen met acties (betaald markeren, crediteren), abonnementen, saldo en kosten.
 * Elke Stripe-fout komt terug als "Stripe-fout: …" en wordt in het foutenoverzicht vastgelegd; elke actie komt in stripe_admin_actions.
 */
class AdminStripeController extends Controller
{
    public function __construct(private StripeAdmin $stripe) {}

    // ── Foutenoverzicht ──────────────────────────────────────────────────

    public function errors(Request $request): JsonResponse
    {
        $data = $request->validate(['handled' => 'nullable|in:0,1', 'source' => 'nullable|string|max:80', 'code' => 'nullable|string|max:80', 'days' => 'nullable|integer|min:1|max:365']);
        $since = now()->subDays((int) ($data['days'] ?? 30));
        $query = StripeError::where('created_at', '>=', $since)->latest('id');
        if (isset($data['handled'])) $query->where('handled', (bool) $data['handled']);
        if (!empty($data['source'])) $query->where('source', 'like', $data['source'].'%');
        if (!empty($data['code'])) $query->where('stripe_code', $data['code']);
        $page = $query->paginate(50);
        $page->getCollection()->transform(function (StripeError $e) {
            $row = $e->toArray();
            $row['hint'] = StripeErrors::hint($e->stripe_code, $e->missing_permission);
            return $row;
        });
        $recent = StripeError::where('created_at', '>=', $since);
        return response()->json([
            'summary' => [
                'last_24h' => StripeError::where('created_at', '>=', now()->subDay())->count(),
                'unhandled' => (clone $recent)->where('handled', false)->count(),
                'handled' => (clone $recent)->where('handled', true)->count(),
                'missing_permissions' => (clone $recent)->whereNotNull('missing_permission')
                    ->selectRaw('missing_permission, count(*) as total, max(created_at) as last_seen')->groupBy('missing_permission')->orderByDesc('last_seen')->get()
                    ->map(fn ($r) => ['permission' => $r->missing_permission, 'total' => (int) $r->total, 'last_seen' => $r->last_seen, 'hint' => StripeErrors::hint(null, $r->missing_permission)]),
                'by_source' => (clone $recent)->selectRaw('source, count(*) as total')->groupBy('source')->orderByDesc('total')->get(),
            ],
            'errors' => $page,
        ]);
    }

    // ── Facturen ─────────────────────────────────────────────────────────

    public function invoices(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => 'nullable|in:all,draft,open,paid,uncollectible,void', 'q' => 'nullable|string|max:120',
            'starting_after' => ['nullable', 'string', 'max:80', 'regex:/^in_[A-Za-z0-9]+$/'], 'limit' => 'nullable|integer|min:1|max:100']);
        return $this->run('invoices', 'invoices', fn () => $this->stripe->invoices($filters), audit: false);
    }

    public function invoice(string $id): JsonResponse
    {
        return $this->run('invoice', $id, fn () => $this->stripe->invoice($id), audit: false);
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        $request->validate(['confirm' => 'accepted']);
        return $this->run('mark_paid', $id, fn () => $this->stripe->markPaid($id), $request);
    }

    public function creditNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['confirm' => 'accepted', 'lines' => 'required|array|min:1|max:50', 'lines.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]+$/', 'max:80'],
            'lines.*.quantity' => 'nullable|integer|min:1|max:100000', 'reason' => ['nullable', Rule::in(StripeAdmin::CREDIT_REASONS)], 'memo' => 'nullable|string|max:500',
            'settlement' => ['nullable', Rule::in(array_keys(StripeAdmin::SETTLEMENTS))], 'subscription' => 'nullable|in:keep,period_end,now']);
        return $this->run('credit_note', $id, function () use ($id, $data) {
            $result = $this->stripe->creditNote($id, $data);
            // Direct daarna het gekoppelde abonnement stilzetten, als de beheerder dat koos.
            $subscriptionId = $result['invoice']['subscription_id'] ?? null;
            if ($subscriptionId && ($data['subscription'] ?? 'keep') === 'period_end') $result['subscription'] = $this->stripe->updateSubscription($subscriptionId, ['cancel_at_period_end' => true])['subscription'];
            if ($subscriptionId && ($data['subscription'] ?? 'keep') === 'now') $result['subscription'] = $this->stripe->cancelSubscription($subscriptionId)['subscription'];
            return $result;
        }, $request, $data);
    }

    // ── Abonnementen ─────────────────────────────────────────────────────

    public function updateSubscription(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['confirm' => 'accepted', 'quantity' => 'nullable|integer|min:1|max:100000', 'cancel_at_period_end' => 'nullable|boolean']);
        return $this->run('subscription_update', $id, fn () => $this->stripe->updateSubscription($id, array_filter($data, fn ($v, $k) => $k !== 'confirm' && $v !== null, ARRAY_FILTER_USE_BOTH)), $request, $data);
    }

    public function cancelSubscription(Request $request, string $id): JsonResponse
    {
        $request->validate(['confirm' => 'accepted']);
        return $this->run('subscription_cancel', $id, fn () => $this->stripe->cancelSubscription($id), $request);
    }

    // ── Saldo en kosten ──────────────────────────────────────────────────

    public function balance(Request $request): JsonResponse
    {
        $data = $request->validate(['months' => 'nullable|integer|min:1|max:12', 'refresh' => 'nullable|boolean']);
        if ($request->boolean('refresh')) \Illuminate\Support\Facades\Cache::forget('stripe-admin-balance:'.(int) ($data['months'] ?? 6));
        return $this->run('balance', 'balance', fn () => $this->stripe->balance((int) ($data['months'] ?? 6)), audit: false);
    }

    // ── Hulpjes ──────────────────────────────────────────────────────────

    /**
     * Voert een Stripe-actie uit. Een Stripe-fout wordt vastgelegd in het foutenoverzicht en komt als "Stripe-fout: …" terug,
     * met het ontbrekende recht erbij als Stripe dat noemde. $audit: acties die iets wijzigen komen in stripe_admin_actions.
     */
    private function run(string $action, string $object, \Closure $do, ?Request $request = null, array $payload = [], bool $audit = true): JsonResponse
    {
        try {
            $result = $do();
        } catch (\Stripe\Exception\ApiErrorException $e) {
            StripeErrors::record($e, 'admin.'.$action, ['stripe_object' => $object]);
            if ($audit) $this->audit($request, $action, $object, $payload, false);
            $code = $e->getStripeCode() ?: (new \ReflectionClass($e))->getShortName();
            $missing = StripeErrors::missingPermission((string) $e->getMessage());
            return response()->json(['message' => 'Stripe-fout: '.StripeErrors::scrub($e->getMessage()),
                'stripe' => ['code' => $code, 'http_status' => $e->getHttpStatus(), 'missing_permission' => $missing, 'hint' => StripeErrors::hint($code, $missing), 'request_id' => $e->getRequestId()]],
                $e instanceof \Stripe\Exception\ApiConnectionException ? 502 : 422);
        }
        if ($audit) $this->audit($request, $action, $object, $payload, true);
        return response()->json($result);
    }

    private function audit(?Request $request, string $action, string $object, array $payload, bool $succeeded): void
    {
        unset($payload['confirm']);
        DB::table('stripe_admin_actions')->insert(['admin_id' => $request?->user()?->id, 'action' => $action, 'stripe_object' => $object,
            'payload' => $payload ? json_encode($payload) : null, 'succeeded' => $succeeded, 'created_at' => now()]);
    }
}
