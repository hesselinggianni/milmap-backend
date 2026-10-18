<?php
namespace Tests\Feature;

use App\Models\{StripeError, User};
use App\Services\StripeAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Tests\TestCase;

/** Nagebootste Stripe-client: onthoudt elke aanroep en weigert aanroepen die in $deny staan met een 403 zoals Stripe bij een beperkte sleutel. */
class AdminStripeFake extends StripeClient {
    public array $calls = []; public array $deny = []; public array $invoiceStore = []; public array $subscriptionStore = []; public array $txStore = [];
    public function __construct() {}
    public function __get($name) { return $this->endpoint($name); }
    private function endpoint(string $path) { return new class($this, $path) {
        public function __construct(private AdminStripeFake $fake, private string $path) {}
        public function __get($name) { return new self($this->fake, $this->path.'.'.$name); }
        public function __call($method, $args) { return $this->fake->call($this->path.'.'.$method, $args); }
    }; }
    public function call(string $method, array $args) {
        $this->calls[] = [$method, $args];
        if (in_array($method, $this->deny, true)) throw \Stripe\Exception\PermissionException::factory("Permission denied. The provided key 'rk_live_...7ed9' does not have the required permissions for this endpoint. Enabling Invoices Write ('invoice_write') permissions on this key would allow this request to continue.", 403, null, null, ['Request-Id' => 'req_test'], 'more_permissions_required');
        switch ($method) {
            case 'customers.all': return (object) ['data' => [(object) ['id' => 'cus_1']]];
            case 'invoices.all': return (object) ['data' => array_values($this->invoiceStore), 'has_more' => false];
            case 'invoices.retrieve': return $this->invoiceStore[$args[0]];
            case 'invoices.finalizeInvoice': $this->invoiceStore[$args[0]]->status = 'open'; return $this->invoiceStore[$args[0]];
            case 'invoices.pay': $i = $this->invoiceStore[$args[0]]; $i->status = 'paid'; $i->amount_paid = $i->total; $i->amount_due = 0; return $i;
            case 'creditNotes.all': return (object) ['data' => []];
            case 'creditNotes.preview': return (object) ['total' => array_sum(array_map(fn ($l) => ($this->invoiceStore[$args[0]['invoice']]->lines->data[array_search($l['invoice_line_item'], array_column($this->invoiceStore[$args[0]['invoice']]->lines->data, 'id'))]->amount / max(1, $this->invoiceStore[$args[0]['invoice']]->lines->data[array_search($l['invoice_line_item'], array_column($this->invoiceStore[$args[0]['invoice']]->lines->data, 'id'))]->quantity)) * $l['quantity'], $args[0]['lines']))];
            case 'creditNotes.create': return (object) ['id' => 'cn_1', 'number' => 'CN-1', 'status' => 'issued', 'reason' => $args[0]['reason'], 'memo' => $args[0]['memo'] ?? null, 'total' => $args[0]['credit_amount'] ?? $args[0]['refund_amount'] ?? $args[0]['out_of_band_amount'] ?? 1000, 'currency' => 'eur', 'created' => now()->timestamp, 'pdf' => null];
            case 'subscriptions.retrieve': return $this->subscriptionStore[$args[0]];
            case 'subscriptions.update':
                $s = $this->subscriptionStore[$args[0]];
                if (isset($args[1]['cancel_at_period_end'])) $s->cancel_at_period_end = $args[1]['cancel_at_period_end'];
                if (isset($args[1]['items'])) $s->items->data[0]->quantity = $args[1]['items'][0]['quantity'];
                return $s;
            case 'subscriptions.cancel': $s = $this->subscriptionStore[$args[0]]; $s->status = 'canceled'; return $s;
            case 'balance.retrieve': return (object) ['available' => [(object) ['amount' => 12345, 'currency' => 'eur']], 'pending' => [(object) ['amount' => 678, 'currency' => 'eur']]];
            case 'balanceTransactions.all': $rows = $this->txStore; return new class($rows) { public function __construct(private array $rows) {} public function autoPagingIterator() { return new \ArrayIterator($this->rows); } };
        }
        throw new \RuntimeException('Unexpected Stripe call: '.$method);
    }
}

class AdminStripeTest extends TestCase {
    use RefreshDatabase;
    private AdminStripeFake $stripe;

    protected function setUp(): void {
        parent::setUp();
        Cache::flush();
        $this->stripe = new AdminStripeFake();
        $customer = (object) ['id' => 'cus_1', 'name' => 'Korps mariniers', 'email' => 'korps@example.org'];
        $line = fn ($id, $desc, $amount, $qty) => (object) ['id' => $id, 'description' => $desc, 'amount' => $amount, 'quantity' => $qty, 'currency' => 'eur', 'period' => (object) ['start' => now()->timestamp, 'end' => now()->addYear()->timestamp]];
        $invoice = fn ($id, $status) => (object) ['id' => $id, 'number' => $status === 'draft' ? null : 'INV-'.$id, 'status' => $status, 'currency' => 'eur', 'total' => 2889, 'amount_due' => $status === 'paid' ? 0 : 2889, 'amount_paid' => $status === 'paid' ? 2889 : 0,
            'customer' => $customer, 'customer_name' => 'Korps mariniers', 'customer_email' => 'korps@example.org', 'created' => now()->timestamp, 'collection_method' => 'send_invoice', 'hosted_invoice_url' => $status === 'draft' ? null : 'https://invoice.stripe.com/x', 'invoice_pdf' => null,
            'parent' => (object) ['subscription_details' => (object) ['subscription' => 'sub_1']],
            'lines' => (object) ['has_more' => false, 'data' => [$line('il_1', 'Connect · 1 seat', 2388, 1), $line('il_2', 'Extra seats', 501, 3)]]];
        $this->stripe->invoiceStore = ['in_draft' => $invoice('in_draft', 'draft'), 'in_open' => $invoice('in_open', 'open'), 'in_paid' => $invoice('in_paid', 'paid')];
        $this->stripe->subscriptionStore = ['sub_1' => (object) ['id' => 'sub_1', 'status' => 'active', 'cancel_at_period_end' => false, 'canceled_at' => null, 'collection_method' => 'send_invoice', 'current_period_end' => now()->addYear()->timestamp,
            'items' => (object) ['data' => [(object) ['id' => 'si_1', 'quantity' => 1, 'price' => (object) ['unit_amount' => 199, 'currency' => 'eur', 'recurring' => (object) ['interval' => 'year', 'interval_count' => 1]]]]]]];
        $fake = $this->stripe;
        $this->app->instance(StripeAdmin::class, new class($fake) extends StripeAdmin {
            public function __construct(private AdminStripeFake $fake) {}
            protected function client(): StripeClient { return $this->fake; }
        });
    }

    private function login(): User {
        $admin = User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('test', ['admin'], now()->addHour())->plainTextToken);
        return $admin;
    }

    public function test_everything_requires_an_admin(): void {
        $this->getJson('/api/v1/admin/stripe/invoices')->assertUnauthorized();
        $this->getJson('/api/v1/admin/stripe/errors')->assertUnauthorized();
        $this->postJson('/api/v1/admin/stripe/invoices/in_open/mark-paid', ['confirm' => true])->assertUnauthorized();
    }

    public function test_invoices_are_listed_and_searchable_with_their_customer(): void {
        $this->login();
        $res = $this->getJson('/api/v1/admin/stripe/invoices')->assertOk()->assertJsonPath('data.0.id', 'in_draft')->assertJsonPath('data.0.customer_email', 'korps@example.org')->assertJsonPath('data.0.subscription_id', 'sub_1');
        $this->assertCount(3, $res->json('data'));
        $this->getJson('/api/v1/admin/stripe/invoices?q=in_open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open');
        $this->getJson('/api/v1/admin/stripe/invoices?q=korps@example.org&status=open')->assertOk();
        $list = collect($this->stripe->calls)->last(fn ($c) => $c[0] === 'invoices.all');
        $this->assertSame('cus_1', $list[1][0]['customer']);$this->assertSame('open', $list[1][0]['status']);
    }

    public function test_invoice_detail_shows_lines_credit_notes_and_the_linked_subscription(): void {
        $this->login();
        $this->getJson('/api/v1/admin/stripe/invoices/in_open')->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('lines.1.quantity', 3)
            ->assertJsonPath('subscription.id', 'sub_1')->assertJsonPath('subscription.items.0.quantity', 1)->assertJsonPath('subscription.items.0.unit_amount', 199)->assertJsonPath('connect_order', null);
    }

    public function test_mark_paid_finalizes_a_draft_first_and_is_audited(): void {
        $admin = $this->login();
        $this->postJson('/api/v1/admin/stripe/invoices/in_draft/mark-paid')->assertUnprocessable();
        $this->postJson('/api/v1/admin/stripe/invoices/in_draft/mark-paid', ['confirm' => true])->assertOk()->assertJsonPath('invoice.status', 'paid')->assertJsonPath('connect_synced', false);
        $this->assertSame(['invoices.retrieve', 'invoices.finalizeInvoice', 'invoices.pay'], array_column($this->stripe->calls, 0));
        $pay = collect($this->stripe->calls)->first(fn ($c) => $c[0] === 'invoices.pay');$this->assertTrue($pay[1][1]['paid_out_of_band']);
        $this->assertDatabaseHas('stripe_admin_actions', ['admin_id' => $admin->id, 'action' => 'mark_paid', 'stripe_object' => 'in_draft', 'succeeded' => true]);
        $this->postJson('/api/v1/admin/stripe/invoices/in_paid/mark-paid', ['confirm' => true])->assertUnprocessable()->assertJsonPath('message', 'Deze factuur is al betaald.');
    }

    public function test_a_stripe_refusal_is_named_as_a_stripe_error_and_listed_in_the_overview(): void {
        $this->login();
        $this->stripe->deny = ['invoices.pay'];
        $res = $this->postJson('/api/v1/admin/stripe/invoices/in_open/mark-paid', ['confirm' => true])->assertUnprocessable();
        $this->assertStringStartsWith('Stripe-fout: ', $res->json('message'));
        $res->assertJsonPath('stripe.code', 'more_permissions_required')->assertJsonPath('stripe.missing_permission', 'invoice_write')->assertJsonPath('stripe.request_id', 'req_test');
        $this->assertStringContainsString("'invoice_write'", $res->json('stripe.hint'));
        $this->assertDatabaseHas('stripe_errors', ['source' => 'admin.mark_paid', 'handled' => false, 'missing_permission' => 'invoice_write', 'stripe_code' => 'more_permissions_required']);
        $this->assertDatabaseHas('stripe_admin_actions', ['action' => 'mark_paid', 'stripe_object' => 'in_open', 'succeeded' => false]);

        StripeError::create(['source' => 'connect.invoice_finalize', 'handled' => true, 'exception' => 'PermissionException', 'message' => 'x', 'stripe_code' => 'more_permissions_required', 'missing_permission' => 'invoice_write']);
        StripeError::create(['source' => 'connect.product', 'handled' => true, 'exception' => 'PermissionException', 'message' => 'y', 'stripe_code' => 'more_permissions_required', 'missing_permission' => 'product_write']);
        StripeError::create(['source' => 'connect.order', 'handled' => false, 'exception' => 'ApiConnectionException', 'message' => 'z']);
        $overview = $this->getJson('/api/v1/admin/stripe/errors')->assertOk();
        $overview->assertJsonPath('summary.last_24h', 4)->assertJsonPath('summary.unhandled', 2)->assertJsonPath('summary.handled', 2);
        $this->assertEqualsCanonicalizing(['invoice_write', 'product_write'], array_column($overview->json('summary.missing_permissions'), 'permission'));
        $this->assertSame(2, collect($overview->json('summary.missing_permissions'))->firstWhere('permission', 'invoice_write')['total']);
        $this->assertStringContainsString("'product_write'", collect($overview->json('summary.missing_permissions'))->firstWhere('permission', 'product_write')['hint']);
        $this->assertCount(4, $overview->json('errors.data'));
        $this->getJson('/api/v1/admin/stripe/errors?handled=0')->assertOk()->assertJsonCount(2, 'errors.data');
        $this->getJson('/api/v1/admin/stripe/errors?source=connect.')->assertOk()->assertJsonCount(3, 'errors.data');
        $this->getJson('/api/v1/admin/stripe/errors?code=more_permissions_required')->assertOk()->assertJsonCount(3, 'errors.data');
    }

    public function test_credit_note_credits_the_selected_lines_and_settles_a_paid_invoice(): void {
        $this->login();
        $lines = [['id' => 'il_2', 'quantity' => 2]];
        $this->postJson('/api/v1/admin/stripe/invoices/in_paid/credit-note', ['lines' => $lines])->assertUnprocessable();
        $this->postJson('/api/v1/admin/stripe/invoices/in_paid/credit-note', ['confirm' => true, 'lines' => $lines, 'reason' => 'order_change', 'memo' => 'Minder seats', 'settlement' => 'refund'])
            ->assertOk()->assertJsonPath('credit_note.id', 'cn_1')->assertJsonPath('credit_note.total', 334)->assertJsonPath('invoice.id', 'in_paid');
        $create = collect($this->stripe->calls)->first(fn ($c) => $c[0] === 'creditNotes.create')[1][0];
        $this->assertSame([['type' => 'invoice_line_item', 'invoice_line_item' => 'il_2', 'quantity' => 2]], $create['lines']);
        $this->assertSame(334, $create['refund_amount']);$this->assertArrayNotHasKey('credit_amount', $create);$this->assertSame('Minder seats', $create['memo']);
        $this->assertDatabaseHas('stripe_admin_actions', ['action' => 'credit_note', 'stripe_object' => 'in_paid', 'succeeded' => true]);
    }

    public function test_credit_note_on_an_open_invoice_needs_no_settlement_and_can_stop_the_subscription_right_after(): void {
        $this->login();
        $this->postJson('/api/v1/admin/stripe/invoices/in_open/credit-note', ['confirm' => true, 'lines' => [['id' => 'il_1'], ['id' => 'il_2']], 'subscription' => 'now'])
            ->assertOk()->assertJsonPath('subscription.status', 'canceled');
        $create = collect($this->stripe->calls)->first(fn ($c) => $c[0] === 'creditNotes.create')[1][0];
        $this->assertArrayNotHasKey('refund_amount', $create);$this->assertArrayNotHasKey('credit_amount', $create);$this->assertSame('order_change', $create['reason']);
        $this->assertSame(['sub_1', ['invoice_now' => false, 'prorate' => false]], collect($this->stripe->calls)->first(fn ($c) => $c[0] === 'subscriptions.cancel')[1]);
    }

    public function test_credit_note_refuses_drafts_and_lines_from_other_invoices(): void {
        $this->login();
        $this->postJson('/api/v1/admin/stripe/invoices/in_draft/credit-note', ['confirm' => true, 'lines' => [['id' => 'il_1']]])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'concept'));
        $this->postJson('/api/v1/admin/stripe/invoices/in_open/credit-note', ['confirm' => true, 'lines' => [['id' => 'il_other']]])->assertUnprocessable();
        $this->postJson('/api/v1/admin/stripe/invoices/in_open/credit-note', ['confirm' => true, 'lines' => [['id' => 'il_2', 'quantity' => 4]]])->assertUnprocessable();
        $this->assertSame(0, collect($this->stripe->calls)->where(0, 'creditNotes.create')->count());
    }

    public function test_the_linked_subscription_can_be_updated_or_stopped(): void {
        $this->login();
        $this->putJson('/api/v1/admin/stripe/subscriptions/sub_1', ['quantity' => 3])->assertUnprocessable();
        $this->putJson('/api/v1/admin/stripe/subscriptions/sub_1', ['confirm' => true, 'quantity' => 3])->assertOk()->assertJsonPath('subscription.items.0.quantity', 3);
        $update = collect($this->stripe->calls)->first(fn ($c) => $c[0] === 'subscriptions.update')[1][1];
        $this->assertSame([['id' => 'si_1', 'quantity' => 3]], $update['items']);$this->assertSame('none', $update['proration_behavior']);
        $this->putJson('/api/v1/admin/stripe/subscriptions/sub_1', ['confirm' => true, 'cancel_at_period_end' => true])->assertOk()->assertJsonPath('subscription.cancel_at_period_end', true);
        $this->putJson('/api/v1/admin/stripe/subscriptions/sub_1', ['confirm' => true])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/stripe/subscriptions/sub_1', ['confirm' => true])->assertOk()->assertJsonPath('subscription.status', 'canceled');
        $this->assertSame(3, DB::table('stripe_admin_actions')->where('succeeded', true)->count());
        $this->stripe->deny = ['subscriptions.cancel'];
        $this->deleteJson('/api/v1/admin/stripe/subscriptions/sub_1', ['confirm' => true])->assertUnprocessable()->assertJsonPath('stripe.missing_permission', 'invoice_write');
    }

    public function test_balance_and_costs_are_summed_per_month(): void {
        $this->login();
        $thisMonth = now()->startOfMonth()->addDays(2)->timestamp; $lastMonth = now()->startOfMonth()->subDays(3)->timestamp;
        $this->stripe->txStore = [
            (object) ['type' => 'charge', 'amount' => 2889, 'fee' => 100, 'currency' => 'eur', 'created' => $thisMonth, 'reporting_category' => 'charge'],
            (object) ['type' => 'charge', 'amount' => 1000, 'fee' => 50, 'currency' => 'eur', 'created' => $thisMonth, 'reporting_category' => 'charge'],
            (object) ['type' => 'stripe_fee', 'amount' => -75, 'fee' => 0, 'currency' => 'eur', 'created' => $thisMonth, 'reporting_category' => 'fee'],
            (object) ['type' => 'refund', 'amount' => -500, 'fee' => 0, 'currency' => 'eur', 'created' => $thisMonth, 'reporting_category' => 'refund'],
            (object) ['type' => 'payout', 'amount' => -3000, 'fee' => 0, 'currency' => 'eur', 'created' => $lastMonth, 'reporting_category' => 'payout'],
            (object) ['type' => 'charge', 'amount' => 500, 'fee' => 30, 'currency' => 'eur', 'created' => $lastMonth, 'reporting_category' => 'charge'],
        ];
        $res = $this->getJson('/api/v1/admin/stripe/balance?months=3')->assertOk()->assertJsonPath('available.0.amount', 12345)->assertJsonPath('pending.0.amount', 678)->assertJsonPath('truncated', false);
        $now = $res->json('months.0');
        $this->assertSame([now()->format('Y-m'), 3889, 225, 3664, -500, 0, 2], [$now['month'], $now['gross'], $now['fees'], $now['net'], $now['refunds'], $now['payouts'], $now['count']]);
        $last = $res->json('months.1');
        $this->assertSame([now()->subMonthNoOverflow()->format('Y-m'), 500, 30, 470, 0, -3000], [$last['month'], $last['gross'], $last['fees'], $last['net'], $last['refunds'], $last['payouts']]);
        $this->getJson('/api/v1/admin/stripe/balance?months=3')->assertOk();
        $this->assertSame(1, collect($this->stripe->calls)->where(0, 'balance.retrieve')->count());
        $this->getJson('/api/v1/admin/stripe/balance?months=3&refresh=1')->assertOk();
        $this->assertSame(2, collect($this->stripe->calls)->where(0, 'balance.retrieve')->count());
    }
}
