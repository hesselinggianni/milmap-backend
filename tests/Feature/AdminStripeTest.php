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
            case 'invoices.finalizeInvoice':
                $i = $this->invoiceStore[$args[0]];
                // Een negatief totaal wordt tegoed bij de klant: de creditfactuur is meteen betaald en krijgt een nummer.
                if ($i->total < 0) { $i->status = 'paid'; $i->number = 'CRD-'.$args[0]; } else $i->status = 'open';
                return $i;
            case 'invoices.pay': $i = $this->invoiceStore[$args[0]]; $i->status = 'paid'; $i->amount_paid = $i->total; $i->amount_due = 0; return $i;
            case 'invoices.create':
                $id = 'in_credit'.(count(array_filter($this->calls, fn ($c) => $c[0] === 'invoices.create')));
                return $this->invoiceStore[$id] = (object) ['id' => $id, 'number' => null, 'status' => 'draft', 'currency' => $args[0]['currency'], 'total' => 0, 'amount_due' => 0, 'amount_paid' => 0, 'customer' => $args[0]['customer'], 'created' => now()->timestamp,
                    'metadata' => (object) $args[0]['metadata'], 'description' => $args[0]['description'], 'lines' => (object) ['has_more' => false, 'data' => []]];
            case 'invoiceItems.create':
                $inv = $this->invoiceStore[$args[0]['invoice']]; $inv->total += $args[0]['amount'];
                $inv->lines->data[] = (object) ['id' => 'il_c'.count($inv->lines->data), 'amount' => $args[0]['amount'], 'description' => $args[0]['description'], 'quantity' => 1, 'currency' => $args[0]['currency']];
                return (object) ['id' => 'ii_'.count($this->calls)];
            case 'invoices.delete': unset($this->invoiceStore[$args[0]]); return (object) ['id' => $args[0], 'deleted' => true];
            case 'invoices.update':
                $i = $this->invoiceStore[$args[0]];
                foreach (($args[1]['metadata'] ?? []) as $k => $v) { if ($v === '') unset($i->metadata->$k); else $i->metadata->$k = $v; }
                return $i;
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
        $taxed = function ($l) { $l->taxes = [(object) ['tax_behavior' => 'exclusive', 'tax_rate_details' => (object) ['tax_rate' => 'txr_vat']]]; return $l; };
        $line = fn ($id, $desc, $amount, $qty) => (object) ['id' => $id, 'description' => $desc, 'amount' => $amount, 'quantity' => $qty, 'currency' => 'eur', 'period' => (object) ['start' => now()->timestamp, 'end' => now()->addYear()->timestamp]];
        $invoice = fn ($id, $status) => (object) ['id' => $id, 'number' => $status === 'draft' ? null : 'INV-'.$id, 'status' => $status, 'currency' => 'eur', 'total' => 2889, 'amount_due' => $status === 'paid' ? 0 : 2889, 'amount_paid' => $status === 'paid' ? 2889 : 0,
            'customer' => $customer, 'customer_name' => 'Korps mariniers', 'customer_email' => 'korps@example.org', 'created' => now()->timestamp, 'collection_method' => 'send_invoice', 'hosted_invoice_url' => $status === 'draft' ? null : 'https://invoice.stripe.com/x', 'invoice_pdf' => null,
            'parent' => (object) ['subscription_details' => (object) ['subscription' => 'sub_1']], 'metadata' => (object) [],
            'lines' => (object) ['has_more' => false, 'data' => [$line('il_1', 'Connect · 1 seat', 2388, 1), $taxed($line('il_2', 'Extra seats', 501, 3))]]];
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

    public function test_invoice_detail_shows_lines_credit_invoices_and_the_linked_subscription(): void {
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

    private const CREDIT = '/api/v1/admin/stripe/invoices/%s/credit-invoice';
    private function creditCalls(string $method): array { return array_values(array_filter($this->stripe->calls, fn ($c) => $c[0] === $method)); }

    public function test_crediting_makes_a_negative_invoice_and_leaves_a_paid_invoice_as_customer_credit(): void {
        $admin = $this->login();
        $lines = [['id' => 'il_2', 'quantity' => 2]]; $url = sprintf(self::CREDIT, 'in_paid');
        $this->postJson($url, ['lines' => $lines])->assertUnprocessable();
        $res = $this->postJson($url, ['confirm' => true, 'lines' => $lines, 'memo' => 'Minder seats'])->assertOk()
            ->assertJsonPath('credit_invoice.number', 'CRD-in_credit1')->assertJsonPath('credit_invoice.status', 'paid')->assertJsonPath('credit_invoice.total', -334)
            ->assertJsonPath('credit_invoice.credits_invoice', 'INV-in_paid')->assertJsonPath('settled', false)->assertJsonPath('invoice.id', 'in_paid');
        $this->assertSame([], array_filter($this->stripe->calls, fn ($c) => str_starts_with($c[0], 'creditNotes.')), 'geen Stripe-creditnota\'s');
        $create = $this->creditCalls('invoices.create')[0][1][0];
        $this->assertSame(['cus_1', 'send_invoice', 'exclude'], [$create['customer'], $create['collection_method'], $create['pending_invoice_items_behavior']]);
        $this->assertStringContainsString('Creditfactuur voor factuur INV-in_paid', $create['description']);$this->assertStringContainsString('Minder seats', $create['description']);
        $this->assertSame(['in_paid', 'INV-in_paid'], [$create['metadata']['credits_invoice'], $create['metadata']['credits_invoice_number']]);
        $item = $this->creditCalls('invoiceItems.create')[0][1][0];
        $this->assertSame([-334, 'in_credit1', ['txr_vat'], 'exclusive'], [$item['amount'], $item['invoice'], $item['tax_rates'], $item['tax_behavior']]);
        $this->assertSame('Creditering: Extra seats (2 van 3) · factuur INV-in_paid', $item['description']);
        $this->assertSame(0, count($this->creditCalls('invoices.pay')), 'een betaalde factuur wordt niet nog eens betaald; het bedrag is tegoed');
        $meta = $this->stripe->invoiceStore['in_paid']->metadata;$this->assertSame(['il_2', 'CRD-in_credit1'], [$meta->credited_lines, $meta->credit_invoices]);
        $this->assertDatabaseHas('stripe_admin_actions', ['admin_id' => $admin->id, 'action' => 'credit_invoice', 'stripe_object' => 'in_paid', 'succeeded' => true]);
        $this->getJson('/api/v1/admin/stripe/invoices/in_paid')->assertOk()->assertJsonPath('credit_invoices.0.number', 'CRD-in_credit1')->assertJsonPath('credit_invoices.0.total', -334)->assertJsonPath('credited_lines', ['il_2']);
        $this->postJson($url, ['confirm' => true, 'lines' => [['id' => 'il_2']]])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'al gecrediteerd'));
        $this->assertSame(1, count($this->creditCalls('invoices.create')));
    }

    public function test_an_open_invoice_that_is_fully_credited_is_paid_with_that_credit_and_the_subscription_can_stop(): void {
        $this->login();
        $res = $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_1'], ['id' => 'il_2']], 'subscription' => 'now'])->assertOk()
            ->assertJsonPath('credit_invoice.total', -2889)->assertJsonPath('settled', true)->assertJsonPath('settle_error', null)->assertJsonPath('invoice.status', 'paid')->assertJsonPath('subscription.status', 'canceled');
        $pay = $this->creditCalls('invoices.pay')[0][1];
        $this->assertSame(['in_open', []], [$pay[0], $pay[1]], 'betaald met het tegoed, niet buiten Stripe');
        $meta = $this->stripe->invoiceStore['in_open']->metadata;
        $this->assertSame('CRD-in_credit1', $meta->credited_by);$this->assertSame('Verrekend met creditfactuur CRD-in_credit1', $meta->settled_note);
        $order = array_column($this->stripe->calls, 0);
        $this->assertLessThan(array_search('invoices.pay', $order), array_search('invoices.update', $order), 'de metadata staat er al vóór de betaling (de webhook leest die)');
        $this->assertSame(['sub_1', ['invoice_now' => false, 'prorate' => false]], $this->creditCalls('subscriptions.cancel')[0][1]);
    }

    public function test_a_partial_credit_on_an_open_invoice_leaves_it_open_and_keeps_the_credit(): void {
        $this->login();
        $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_2']]])->assertOk()->assertJsonPath('settled', false)->assertJsonPath('invoice.status', 'open');
        $this->assertSame(0, count($this->creditCalls('invoices.pay')));
        $meta = $this->stripe->invoiceStore['in_open']->metadata;$this->assertFalse(isset($meta->credited_by));$this->assertSame('il_2', $meta->credited_lines);
    }

    public function test_crediting_refuses_drafts_credit_invoices_and_wrong_lines(): void {
        $this->login();
        $this->postJson(sprintf(self::CREDIT, 'in_draft'), ['confirm' => true, 'lines' => [['id' => 'il_1']]])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'concept'));
        $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_other']]])->assertUnprocessable();
        $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_2', 'quantity' => 4]]])->assertUnprocessable();
        $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_1']]])->assertOk();
        $this->postJson(sprintf(self::CREDIT, 'in_credit1'), ['confirm' => true, 'lines' => [['id' => 'il_c0']]])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'creditfactuur'));
        $this->assertSame(1, count($this->creditCalls('invoices.create')));
    }

    public function test_a_refused_credit_leaves_no_half_finished_credit_invoice_behind(): void {
        $this->login();
        $this->stripe->deny = ['invoiceItems.create'];
        $res = $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_1']]])->assertUnprocessable();
        $this->assertStringStartsWith('Stripe-fout: ', $res->json('message'));$res->assertJsonPath('stripe.missing_permission', 'invoice_write');
        $this->assertSame(1, count($this->creditCalls('invoices.delete')));$this->assertArrayNotHasKey('in_credit1', $this->stripe->invoiceStore);
        $this->assertDatabaseHas('stripe_errors', ['source' => 'admin.credit_invoice', 'handled' => false]);
        $this->assertDatabaseHas('stripe_admin_actions', ['action' => 'credit_invoice', 'stripe_object' => 'in_open', 'succeeded' => false]);
    }

    public function test_when_settling_fails_the_credit_invoice_stays_and_the_original_is_not_marked_credited(): void {
        $this->login();
        $this->stripe->deny = ['invoices.pay'];
        $this->postJson(sprintf(self::CREDIT, 'in_open'), ['confirm' => true, 'lines' => [['id' => 'il_1'], ['id' => 'il_2']]])->assertOk()
            ->assertJsonPath('settled', false)->assertJsonPath('credit_invoice.number', 'CRD-in_credit1')->assertJsonPath('settle_error', fn ($m) => str_contains($m, 'Permission denied'));
        $meta = $this->stripe->invoiceStore['in_open']->metadata;$this->assertFalse(isset($meta->credited_by));$this->assertSame('CRD-in_credit1', $meta->credit_invoices);
        $this->assertDatabaseHas('stripe_errors', ['source' => 'admin.credit_invoice_settle', 'handled' => true]);
        $this->assertArrayHasKey('in_credit1', $this->stripe->invoiceStore);
    }

    public function test_marking_paid_can_carry_a_note_in_the_invoice_metadata(): void {
        $this->login();
        $this->postJson('/api/v1/admin/stripe/invoices/in_open/mark-paid', ['confirm' => true, 'note' => 'Bank 18-10, ref 123'])->assertOk()->assertJsonPath('invoice.note', 'Bank 18-10, ref 123');
        $this->assertSame('Bank 18-10, ref 123', $this->stripe->invoiceStore['in_open']->metadata->paid_out_of_band_note);
        $this->assertSame(['invoices.retrieve', 'invoices.update', 'invoices.pay'], array_column($this->stripe->calls, 0));
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
