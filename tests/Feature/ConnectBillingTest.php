<?php
namespace Tests\Feature;
use App\Models\{ConnectAllowedDevice, ConnectOrganization, ConnectMember, ConnectOrder, ConnectOrderInvoice, ConnectPendingAddition, User};
use App\Services\ConnectBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail};
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Tests\TestCase;
class ConnectStripeFake extends StripeClient {
    public array $calls=[]; public bool $restricted=false; public bool $failCustomerUpdate=false; public bool $failCheckout=false; public bool $failSendInvoice=false;
    public int $unitAmount=200; public array $subscriptionObjects=[]; public array $invoiceObjects=[]; public ?\Closure $checkoutCreated=null;
    public function __construct() {}
    public function __get($name) { return $this->endpoint($name); }
    private function endpoint(string $path) { return new class($this,$path) {
        public function __construct(private ConnectStripeFake $fake,private string $path) {}
        public function __get($name) { return new self($this->fake,$this->path.'.'.$name); }
        public function __call($method,$args) { return $this->fake->call($this->path.'.'.$method,$args); }
    }; }
    public function call(string $method,array $args) {
        $this->calls[]=[$method,$args];
        if($method==='prices.retrieve')return (object)['active'=>true,'type'=>'recurring','recurring'=>(object)['interval'=>'month','interval_count'=>1],'unit_amount'=>$this->unitAmount,'product'=>'prod_connect','currency'=>'eur','tax_behavior'=>'unspecified'];
        if(in_array($method,['products.create','taxRates.create'],true)&&$this->restricted)throw \Stripe\Exception\PermissionException::factory('Permission denied',403);
        if($method==='products.create')return (object)['id'=>'prod_org'];
        if($method==='taxRates.create')return (object)['id'=>'txr_vat'];
        if($method==='customers.update'&&$this->failCustomerUpdate)throw \Stripe\Exception\InvalidRequestException::factory('Invalid address',400);
        if($method==='checkout.sessions.create'&&$this->failCheckout)throw \Stripe\Exception\ApiConnectionException::factory('Could not connect');
        if($method==='customers.create'||$method==='customers.update')return (object)['id'=>'cus_org'];
        if($method==='checkout.sessions.create'){$n=count(array_filter($this->calls,fn($c)=>$c[0]===$method));$session=(object)['id'=>'cs_connect'.($n>1?'_'.$n:''),'url'=>($args[0]['ui_mode']??null)==='embedded' ? null : 'https://checkout.stripe.com/test-connect','client_secret'=>'cs_secret_'.$n,'status'=>'open'];if($this->checkoutCreated)($this->checkoutCreated)($session);return $session;}
        if($method==='checkout.sessions.retrieve')return (object)['id'=>$args[0],'status'=>'open','client_secret'=>'cs_secret_retrieved'];
        if($method==='subscriptions.create') {
            $n=count(array_filter($this->calls,fn($c)=>$c[0]===$method));$id='sub_connect'.($n>1?'_'.$n:'');$invoiceId='in_first'.($n>1?'_'.$n:'');
            $recurring=$args[0]['items'][0]['price_data']['recurring'];$months=$recurring['interval']==='year' ? 12 : $recurring['interval_count'];
            $invoice=(object)['id'=>$invoiceId,'subscription'=>$id,'status'=>'open','number'=>'INV-'.$n,'total'=>1200,'currency'=>'eur','hosted_invoice_url'=>'https://invoice.stripe.com/test','invoice_pdf'=>'https://files.stripe.com/test.pdf',
                'lines'=>(object)['data'=>[(object)['type'=>'subscription','subscription'=>$id,'period'=>(object)['start'=>now()->timestamp,'end'=>now()->addMonths($months)->timestamp]]]]];
            $this->invoiceObjects[$invoiceId]=$invoice;
            return $this->subscriptionObjects[$id]=(object)['id'=>$id,'latest_invoice'=>$invoice,'metadata'=>(object)$args[0]['metadata']];
        }
        if($method==='subscriptions.retrieve')return $this->subscriptionObjects[$args[0]];
        if($method==='invoices.sendInvoice'&&$this->failSendInvoice)throw \Stripe\Exception\ApiConnectionException::factory('Could not connect');
        if(in_array($method,['invoices.sendInvoice','invoices.retrieve'],true))return $this->invoiceObjects[$args[0]];
        if($method==='subscriptions.update')return (object)['id'=>'sub_connect','cancel_at_period_end'=>true];
        throw new \RuntimeException('Unexpected Stripe call: '.$method);
    }
}
class ConnectBillingTest extends TestCase {
    use RefreshDatabase;
    private ConnectOrganization $org; private User $owner; private ConnectStripeFake $stripe; private ConnectBilling $billing;
    protected function setUp(): void {
        parent::setUp();Mail::fake();config(['billing.stripe_price_connect_seat_monthly'=>'price_connect']);
        $this->owner=User::create(['first_name'=>'Owner','last_name'=>'Test','email'=>'owner@example.org','password'=>bcrypt('Password1!'),'email_verified_at'=>now()]);
        $this->org=ConnectOrganization::create(['name'=>'Workspace','seat_limit'=>2,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear()]);
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$this->owner->id,'role'=>'owner','status'=>'active','email'=>$this->owner->email,'first_name'=>'Owner','last_name'=>'Test']);
        $this->actingAs($this->owner,'sanctum');$this->stripe=new ConnectStripeFake();
        $this->billing=new class($this->stripe) extends ConnectBilling { public function __construct(private ConnectStripeFake $fake) {} protected function stripe(): StripeClient { return $this->fake; } };
        $this->app->instance(ConnectBilling::class,$this->billing);
    }
    private function payload(string $method='direct',int $months=12): array { return ['request_id'=>(string)Str::uuid(),'seats'=>3,'term_months'=>$months,'payment_method'=>$method,'accepted'=>true,'expected_unit_monthly_cents'=>200,'expected_total_incl_cents'=>$months*726,'expected_currency'=>'EUR','billing_details'=>['company_name'=>'Workspace','email'=>'billing@example.org','address'=>['line1'=>'Street 1','postal_code'=>'1000AA','city'=>'Amsterdam','country'=>'NL']]]; }
    private function path(): string { return '/api/v1/connect/organizations/'.$this->org->id.'/billing'; }
    private function details(array $address=[]): array { return ['company_name'=>'Workspace BV','email'=>'facturen@example.org','address'=>$address+['line1'=>'Kade 2','line2'=>'t.a.v. inkoop','postal_code'=>'3011AA','city'=>'Rotterdam','country'=>'BE']]; }
    private function customerCalls(string $method): array { return array_values(array_filter($this->stripe->calls,fn($c)=>$c[0]==='customers.'.$method)); }
    private function invoice(ConnectOrder $order,string $id='in_paid'): object {
        $subscription=$order->stripe_subscription_id ?: 'sub_connect';
        return (object)['id'=>$id,'metadata'=>(object)['connect_order_id'=>$order->id],'customer'=>'cus_org','subscription'=>$subscription,'status'=>'paid','currency'=>'eur','total'=>$order->total_incl_cents,'number'=>'INV-PAID',
            'lines'=>(object)['data'=>[(object)['parent'=>(object)['type'=>'subscription_item_details','subscription_item_details'=>(object)['subscription'=>$subscription,'subscription_item'=>'si_connect','proration'=>false]],
                'period'=>(object)['start'=>now()->timestamp,'end'=>now()->addMonths($order->term_months)->timestamp]]]],'status_transitions'=>(object)['paid_at'=>now()->timestamp]];
    }
    public function test_direct_checkout_uses_server_price_selected_term_and_idempotent_order(): void {
        $data=$this->payload(); $res=$this->postJson($this->path().'/orders',$data)->assertCreated()->assertJsonPath('order.total_cents',7200);
        $this->postJson($this->path().'/orders',$data)->assertCreated()->assertJsonPath('order.id',$res->json('order.id'));
        $calls=array_values(array_filter($this->stripe->calls,fn($c)=>$c[0]==='checkout.sessions.create'));
        $this->assertCount(1,$calls);$params=$calls[0][1][0];
        $this->assertSame(2400,$params['line_items'][0]['price_data']['unit_amount']);$this->assertSame('year',$params['line_items'][0]['price_data']['recurring']['interval']);$this->assertSame('prod_org',$params['line_items'][0]['price_data']['product']);$this->assertSame(3,$params['line_items'][0]['quantity']);
        $this->assertSame(2,$this->org->fresh()->seat_limit);$this->assertDatabaseCount('connect_orders',1);
        $this->assertStringNotContainsString('billing@example.org',DB::table('connect_orders')->value('billing_details'));
    }
    public function test_embedded_checkout_is_used_only_when_asked_and_a_publishable_key_exists(): void {
        // Zonder publishable key: gehoste checkout als terugval, ook als de klant embedded vraagt.
        $res=$this->postJson($this->path().'/orders',['embedded'=>true]+$this->payload())->assertCreated();
        $this->assertNotEmpty($res->json('checkout_url')); $this->assertNull($res->json('client_secret'));
        $p=array_values(array_filter($this->stripe->calls,fn($c)=>$c[0]==='checkout.sessions.create'))[0][1][0];
        $this->assertArrayHasKey('cancel_url',$p); $this->assertArrayNotHasKey('ui_mode',$p);
        // Met key + embedded: geen Stripe-pagina, wel client_secret en een return_url naar Connect.
        config(['billing.stripe_key'=>'pk_test_123']);
        $res=$this->postJson($this->path().'/orders',['embedded'=>true]+$this->payload())->assertCreated();
        $this->assertNull($res->json('checkout_url')); $this->assertSame('pk_test_123',$res->json('publishable_key')); $this->assertNotEmpty($res->json('client_secret'));
        $p=array_values(array_filter($this->stripe->calls,fn($c)=>$c[0]==='checkout.sessions.create'))[1][1][0];
        $this->assertSame('embedded',$p['ui_mode']); $this->assertArrayNotHasKey('cancel_url',$p); $this->assertStringContainsString('{CHECKOUT_SESSION_ID}',$p['return_url']);
        // Zonder embedded-verzoek blijft het gehost.
        $this->postJson($this->path().'/orders',$this->payload())->assertCreated()->assertJsonPath('client_secret',null);
    }
    public function test_extra_seats_run_with_the_current_license_and_pay_pro_rata_until_its_end(): void {
        // Lopende betaalde licentie: nog precies 100 dagen. Jaarbestelling = pro rata tot die datum, daarna verlengt alles samen.
        $this->org->licenses()->create(['name'=>'Connect · 100 seats · 12 maanden','seat_count'=>100,'monthly_price_cents'=>20000,'currency'=>'EUR','starts_at'=>now()->subDays(265),'ends_at'=>now()->addDays(100),'source_reference'=>'stripe-invoice:in_base']);
        $cat=$this->getJson($this->path().'/catalog')->assertOk()->json('pricing.coterm');
        $this->assertSame(100,$cat['12']['remaining_days']); $this->assertSame(365,$cat['12']['term_days']);
        $expected=(int)round(200*12*100/365);
        $this->assertSame($expected,$cat['12']['unit_term_cents']);
        $this->assertNull($cat['1'],'maandtermijn: einddatum ligt verder weg dan één maand');
        $body=$this->payload('direct',12); $total=$this->postJson($this->path().'/orders',$body)->assertStatus(409); // totaal klopt niet: nog het volle jaar
        $amounts=ConnectBilling::amounts($expected,1,3,false,'tax_rate',21);
        $body['expected_total_incl_cents']=$amounts['incl_cents'];
        $res=$this->postJson($this->path().'/orders',$body)->assertCreated();
        $this->assertSame($amounts['incl_cents'],$res->json('order.total_incl_cents'));
        $end=\Illuminate\Support\Carbon::parse($res->json('order.coterm_ends_at'))->getTimestamp();
        $p=array_values(array_filter($this->stripe->calls,fn($c)=>$c[0]==='checkout.sessions.create'))[0][1][0];
        $this->assertSame($end,$p['subscription_data']['billing_cycle_anchor']); $this->assertSame('none',$p['subscription_data']['proration_behavior']);
        $this->assertCount(2,$p['line_items']);
        $this->assertArrayNotHasKey('recurring',$p['line_items'][0]['price_data'],'eerste regel is eenmalig (pro rata)');
        $this->assertSame(2400,$p['line_items'][1]['price_data']['unit_amount'],'volle termijn pas vanaf de einddatum');
        $this->assertSame((int)round($expected),$p['line_items'][0]['price_data']['unit_amount']);
        // Betaling: de bijbestelling krijgt dezelfde einddatum als de lopende licentie (Stripe-periode op de eerste factuur is dan niet bruikbaar).
        $order=ConnectOrder::findOrFail($res->json('order.id'));
        $this->billing->webhook('checkout.session.completed',(object)['id'=>'cs_connect','subscription'=>'sub_connect','metadata'=>(object)['connect_order_id'=>$order->id],'payment_status'=>'paid']);
        $inv=$this->invoice($order,'in_topup'); $inv->lines->data[0]->period=(object)['start'=>now()->timestamp,'end'=>now()->timestamp];
        $this->billing->webhook('invoice.paid',$inv);
        $lic=$this->org->licenses()->where('source_reference','stripe-invoice:in_topup')->firstOrFail();
        $this->assertSame($end,$lic->ends_at->getTimestamp()); $this->assertSame(3,$lic->seat_count);
        $this->assertSame(103,$this->org->fresh()->seat_limit);
        // Verlenging op de einddatum: gewone periode van een volle termijn, alle seats samen.
        $renew=$this->invoice($order,'in_renew'); $renew->lines->data[0]->period=(object)['start'=>$end,'end'=>\Illuminate\Support\Carbon::createFromTimestamp($end)->addYear()->timestamp];
        $renew->status_transitions=(object)['paid_at'=>$end+60];
        $this->billing->webhook('invoice.paid',$renew);
        $this->assertSame(\Illuminate\Support\Carbon::createFromTimestamp($end)->addYear()->timestamp,$this->org->licenses()->where('source_reference','stripe-invoice:in_renew')->firstOrFail()->ends_at->getTimestamp());
    }
    public function test_six_month_terms_are_available_for_direct_and_invoice_subscriptions(): void {
        $this->getJson($this->path().'/catalog')->assertOk()->assertJsonPath('pricing.terms',[1,6,12]);
        foreach (['direct','invoice'] as $method) $this->postJson($this->path().'/orders',$this->payload($method,6))->assertCreated()->assertJsonPath('order.term_months',6);
        $checkout=collect($this->stripe->calls)->first(fn($c)=>$c[0]==='checkout.sessions.create')[1][0]['line_items'][0];
        $invoice=collect($this->stripe->calls)->first(fn($c)=>$c[0]==='subscriptions.create')[1][0]['items'][0];
        foreach ([$checkout,$invoice] as $item) {
            $this->assertSame(['interval'=>'month','interval_count'=>6],$item['price_data']['recurring']);
            $this->assertSame(1200,$item['price_data']['unit_amount']);
        }
        $this->assertSame(2,$this->org->fresh()->seat_limit);
    }
    public function test_a_retried_failed_checkout_cannot_charge_a_different_price_than_accepted(): void {
        $payload=$this->payload();$this->stripe->failCheckout=true;
        $this->postJson($this->path().'/orders',$payload)->assertUnprocessable();
        $this->stripe->unitAmount=100;$this->stripe->failCheckout=false;
        $this->postJson($this->path().'/orders',['expected_unit_monthly_cents'=>100,'expected_total_incl_cents'=>4356]+$payload)->assertConflict();
        $this->assertSame(1,collect($this->stripe->calls)->where(0,'checkout.sessions.create')->count());
        $this->assertDatabaseCount('connect_orders',1);
    }
    public function test_integer_strings_do_not_break_an_idempotent_order_retry(): void {
        $payload=$this->payload();$payload['seats']='3';$payload['term_months']='12';
        $first=$this->postJson($this->path().'/orders',$payload)->assertCreated()->json('order.id');
        $this->postJson($this->path().'/orders',$payload)->assertCreated()->assertJsonPath('order.id',$first);
        $this->assertDatabaseCount('connect_orders',1);
    }
    public function test_invoice_order_creates_payable_invoice_without_granting_capacity(): void {
        $res=$this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated()->assertJsonPath('order.invoices.0.status','open');
        $this->assertSame(2,$this->org->fresh()->seat_limit);$this->assertDatabaseCount('connect_order_invoices',1);
        $call=collect($this->stripe->calls)->first(fn($c)=>$c[0]==='subscriptions.create');
        $this->assertSame('send_invoice',$call[1][0]['collection_method']);$this->assertSame(14,$call[1][0]['days_until_due']);
        $this->getJson($this->path().'/orders')->assertOk()->assertJsonPath('data.0.id',$res->json('order.id'));
    }
    public function test_checkout_completion_is_not_payment_and_paid_invoice_is_idempotent(): void {
        $res=$this->postJson($this->path().'/orders',$this->payload())->assertCreated();$order=ConnectOrder::findOrFail($res->json('order.id'));
        $this->billing->webhook('checkout.session.completed',(object)['id'=>'cs_connect','subscription'=>'sub_connect','metadata'=>(object)['connect_order_id'=>$order->id],'payment_status'=>'unpaid']);
        $this->assertSame(2,$this->org->fresh()->seat_limit);
        $invoice=$this->invoice($order);$this->billing->webhook('invoice.paid',$invoice);$this->billing->webhook('invoice.paid',$invoice);
        $this->assertSame(5,$this->org->fresh()->seat_limit);$this->assertSame(1,$this->org->licenses()->where('source_reference','stripe-invoice:in_paid')->count());
        $this->assertSame('paid',$order->fresh()->status);$this->assertSame('paid',ConnectOrderInvoice::where('stripe_invoice_id','in_paid')->first()->status);
    }
    public function test_a_paid_finalized_snapshot_does_not_prevent_payment_provisioning(): void {
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload())->assertCreated()->json('order.id'));
        $invoice=$this->invoice($order);$this->billing->webhook('invoice.finalized',$invoice);
        $this->assertSame(2,$this->org->fresh()->seat_limit);
        $this->billing->webhook('invoice.paid',$invoice);$this->billing->webhook('invoice.paid',$invoice);
        $this->assertSame(5,$this->org->fresh()->seat_limit);
        $this->assertSame(1,$this->org->licenses()->where('source_reference','stripe-invoice:in_paid')->count());
    }
    public function test_late_failure_and_checkout_events_preserve_paid_and_cancelled_orders(): void {
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload())->assertCreated()->json('order.id'));
        $invoice=$this->invoice($order);$this->billing->webhook('invoice.paid',$invoice);
        $invoice->status='open';$this->billing->webhook('invoice.payment_failed',$invoice);
        $this->assertSame('paid',$order->fresh()->status);
        $subscription=(object)['id'=>'sub_connect','customer'=>'cus_org','metadata'=>(object)[]];
        $this->assertTrue($this->billing->webhook('customer.subscription.deleted',$subscription),'subscription ID resolves orders even without metadata');
        $this->billing->webhook('checkout.session.completed',(object)['id'=>'cs_connect','subscription'=>'sub_connect','metadata'=>(object)['connect_order_id'=>$order->id]]);
        $invoice->status='paid';$this->billing->webhook('invoice.paid',$invoice);
        $subscription->cancel_at_period_end=true;$this->billing->webhook('customer.subscription.updated',$subscription);
        $this->assertSame('cancelled',$order->fresh()->status);$this->assertSame(5,$this->org->fresh()->seat_limit);
    }
    public function test_late_payment_for_an_old_period_does_not_clear_a_newer_failed_renewal(): void {
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload())->assertCreated()->json('order.id'));
        $old=$this->invoice($order,'in_old');$new=$this->invoice($order,'in_new');$new->status='open';
        $new->lines->data[0]->period=(object)['start'=>now()->addYear()->timestamp,'end'=>now()->addYears(2)->timestamp];
        $this->billing->webhook('invoice.payment_failed',$new);$this->billing->webhook('invoice.paid',$old);
        $this->assertSame('payment_failed',$order->fresh()->status);
        $this->assertSame(5,$this->org->fresh()->seat_limit,'the paid older service period is still granted');
    }
    public function test_payment_uses_the_recurring_service_line_instead_of_a_pending_adjustment(): void {
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload('invoice',6))->assertCreated()->json('order.id'));
        $invoice=$this->invoice($order);$expected=$invoice->lines->data[0]->period;
        array_unshift($invoice->lines->data,(object)['parent'=>(object)['type'=>'invoice_item_details'],'period'=>(object)['start'=>now()->timestamp,'end'=>now()->timestamp]]);
        // Pending fees, discounts or credits may legitimately alter the invoice total.
        $invoice->total+=123;$this->billing->webhook('invoice.paid',$invoice);
        $license=$this->org->licenses()->where('source_reference','stripe-invoice:in_paid')->firstOrFail();
        $this->assertSame($expected->start,$license->starts_at->timestamp);$this->assertSame($expected->end,$license->ends_at->timestamp);
        $this->assertSame(5,$this->org->fresh()->seat_limit);
    }
    public function test_renewals_replace_expired_capacity_for_the_paid_service_period(): void {
        $this->travelTo(now()->startOfSecond());$start=now();
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload('invoice',1))->assertCreated()->json('order.id'));
        $this->billing->webhook('invoice.paid',$this->invoice($order,'in_month_1'));
        $renewal=$this->invoice($order,'in_month_2');$renewal->lines->data[0]->period=(object)['start'=>$start->copy()->addMonth()->timestamp,'end'=>$start->copy()->addMonths(2)->timestamp];
        $this->billing->webhook('invoice.paid',$renewal);
        $this->assertSame(5,$this->org->fresh()->seat_limit,'a prepaid future period must not double current capacity');
        $this->travelTo($start->copy()->addMonth()->addSecond());$this->assertSame(5,$this->org->fresh()->seat_limit);
        $this->travelTo($start->copy()->addMonths(2)->addSecond());$this->assertSame(2,$this->org->fresh()->seat_limit);
    }
    public function test_modern_parent_metadata_and_legacy_service_lines_are_supported(): void {
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload())->assertCreated()->json('order.id'));
        $invoice=$this->invoice($order);$period=$invoice->lines->data[0]->period;
        $invoice->parent=(object)['subscription_details'=>(object)['subscription'=>(object)['id'=>'sub_connect'],'metadata'=>$invoice->metadata]];
        unset($invoice->subscription,$invoice->metadata);
        $invoice->lines->data=[(object)['type'=>'subscription','subscription'=>(object)['id'=>'sub_connect'],'proration'=>false,'period'=>$period]];
        $this->assertTrue($this->billing->webhook('invoice.paid',$invoice));$this->assertSame(5,$this->org->fresh()->seat_limit);
    }
    public function test_a_paid_event_arriving_before_checkout_returns_keeps_the_paid_status(): void {
        $this->stripe->checkoutCreated=function () { $order=ConnectOrder::firstOrFail();$this->billing->webhook('invoice.paid',$this->invoice($order)); };
        $this->postJson($this->path().'/orders',$this->payload())->assertCreated()->assertJsonPath('order.status','paid');
        $this->assertSame(5,$this->org->fresh()->seat_limit);
    }
    public function test_an_invoice_retry_recovers_after_sending_failed_even_when_a_webhook_recorded_it(): void {
        $payload=$this->payload('invoice');$this->stripe->failSendInvoice=true;
        $this->postJson($this->path().'/orders',$payload)->assertUnprocessable();
        $order=ConnectOrder::firstOrFail();$invoice=$this->stripe->invoiceObjects['in_first'];$invoice->metadata=(object)['connect_order_id'=>$order->id];$invoice->customer='cus_org';
        $this->billing->webhook('invoice.finalized',$invoice);
        $this->stripe->failSendInvoice=false;
        $this->postJson($this->path().'/orders',$payload)->assertCreated()->assertJsonPath('order.invoices.0.status','open');
        $this->assertSame(1,collect($this->stripe->calls)->where(0,'subscriptions.create')->count());
        $this->assertSame(2,collect($this->stripe->calls)->where(0,'invoices.sendInvoice')->count());
        $this->assertSame(2,$this->org->fresh()->seat_limit);
    }
    public function test_payment_cannot_be_attached_to_another_customer_subscription_or_currency(): void {
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated()->json('order.id'));
        $mutations=[
            fn($i)=>$i->customer='cus_someone_else',
            fn($i)=>$i->subscription='sub_someone_else',
            fn($i)=>$i->metadata->connect_organization_id='999999',
            fn($i)=>$i->currency='usd',
            fn($i)=>$i->lines->data[0]->parent->subscription_item_details->proration=true,
        ];
        foreach ($mutations as $n=>$mutate) {
            $invoice=$this->invoice($order,'in_rejected_'.$n);$mutate($invoice);
            try {
                $this->billing->webhook('invoice.paid',$invoice);$this->fail('Invalid payment binding or service period was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                $this->assertContains($e->getStatusCode(),[409,422]);
            }
            $this->assertSame(2,$this->org->fresh()->seat_limit);
            $this->assertDatabaseMissing('connect_order_invoices',['stripe_invoice_id'=>$invoice->id]);
        }
    }
    public function test_managers_cannot_read_order_or_cancel_another_tenants_billing(): void {
        $other=ConnectOrganization::create(['name'=>'Other','seat_limit'=>1,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear()]);
        $otherPath='/api/v1/connect/organizations/'.$other->id.'/billing';
        $this->getJson($otherPath.'/catalog')->assertForbidden();$this->getJson($otherPath.'/orders')->assertForbidden();
        $this->postJson($otherPath.'/orders',$this->payload())->assertForbidden();
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated()->json('order.id'));
        $otherOrder=$order->replicate(['stripe_subscription_id','stripe_checkout_id']);$otherOrder->organization_id=$other->id;$otherOrder->save();
        $this->postJson($this->path().'/orders/'.$otherOrder->id.'/cancel')->assertNotFound();
        $this->assertNull(collect($this->stripe->calls)->first(fn($c)=>$c[0]==='subscriptions.update'));
    }
    public function test_only_managers_can_order_and_changed_prices_invalid_terms_are_rejected(): void {
        $data=$this->payload();$data['term_months']=3;$this->postJson($this->path().'/orders',$data)->assertUnprocessable();
        $data=$this->payload();$data['expected_unit_monthly_cents']=1;$this->postJson($this->path().'/orders',$data)->assertConflict();
        $member=User::create(['first_name'=>'Member','last_name'=>'Test','email'=>'member@example.org','password'=>bcrypt('Password1!'),'email_verified_at'=>now()]);
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$member->id,'role'=>'member','status'=>'active','email'=>$member->email,'first_name'=>'Member','last_name'=>'Test']);
        $this->actingAs($member,'sanctum')->postJson($this->path().'/orders',$this->payload())->assertForbidden();
        $this->getJson($this->path().'/orders')->assertForbidden();
    }
    public function test_cancelling_renewal_preserves_already_paid_capacity(): void {
        $res=$this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated();$order=ConnectOrder::findOrFail($res->json('order.id'));
        $this->billing->webhook('invoice.paid',$this->invoice($order));
        $this->postJson($this->path().'/orders/'.$order->id.'/cancel')->assertOk()->assertJsonPath('order.status','cancellation_requested');
        $this->assertSame(5,$this->org->fresh()->seat_limit);
    }
    public function test_auto_renew_off_stops_renewal_and_uses_a_customer_product(): void {
        $this->postJson($this->path().'/orders',['auto_renew'=>false]+$this->payload('invoice',1))->assertCreated();
        $this->assertSame('prod_org',$this->org->fresh()->stripe_product_id);
        $this->assertNotNull(collect($this->stripe->calls)->first(fn($c)=>$c[0]==='subscriptions.update' && ($c[1][1]['cancel_at_period_end'] ?? $c[1][0] ?? null)===true || ($c[0]==='subscriptions.update' && ($c[1][1]['cancel_at_period_end'] ?? false))));
        $this->assertFalse((bool)ConnectOrder::first()->auto_renew);
    }
    public function test_vat_is_shown_and_charged_on_top_of_an_exclusive_price_with_a_stripe_tax_rate(): void {
        $this->getJson($this->path().'/catalog')->assertOk()->assertJsonPath('pricing.prices_include_vat',false)->assertJsonPath('pricing.vat_percent',21)
            ->assertJsonPath('pricing.vat_method','tax_rate')->assertJsonMissingPath('pricing.tax_rate_id');
        $res=$this->postJson($this->path().'/orders',['expected_total_incl_cents'=>8712]+$this->payload())->assertCreated()
            ->assertJsonPath('order.total_cents',7200)->assertJsonPath('order.vat_cents',1512)->assertJsonPath('order.total_incl_cents',8712);
        $item=collect($this->stripe->calls)->first(fn($c)=>$c[0]==='checkout.sessions.create')[1][0]['line_items'][0];
        $this->assertSame(['txr_vat'],$item['tax_rates']);$this->assertSame('exclusive',$item['price_data']['tax_behavior']);$this->assertSame(2400,$item['price_data']['unit_amount']);
        $this->assertSame(1,collect($this->stripe->calls)->where(0,'taxRates.create')->count());
        $this->postJson($this->path().'/orders',['expected_total_incl_cents'=>8712]+$this->payload('invoice'))->assertCreated();
        $this->assertSame(1,collect($this->stripe->calls)->where(0,'taxRates.create')->count(),'het btw-tarief wordt hergebruikt');
        $this->assertSame(['txr_vat'],collect($this->stripe->calls)->first(fn($c)=>$c[0]==='subscriptions.create')[1][0]['items'][0]['tax_rates']);
    }
    public function test_without_stripe_tax_rate_permission_vat_is_folded_into_the_seat_price(): void {
        $this->stripe->restricted=true;
        $this->getJson($this->path().'/catalog')->assertOk()->assertJsonPath('pricing.vat_method','gross_price');
        $this->postJson($this->path().'/orders',['expected_total_incl_cents'=>8712]+$this->payload())->assertCreated()->assertJsonPath('order.total_incl_cents',8712);
        $item=collect($this->stripe->calls)->first(fn($c)=>$c[0]==='checkout.sessions.create')[1][0]['line_items'][0];
        $this->assertArrayNotHasKey('tax_rates',$item);$this->assertSame('inclusive',$item['price_data']['tax_behavior']);
        $this->assertSame(2904,$item['price_data']['unit_amount']);$this->assertSame('prod_connect',$item['price_data']['product']);
        $this->assertNull($this->org->fresh()->stripe_product_id);
    }
    public function test_a_changed_total_is_rejected_before_charging(): void {
        $this->postJson($this->path().'/orders',['expected_total_incl_cents'=>7200]+$this->payload())->assertConflict();
        $this->assertNull(collect($this->stripe->calls)->first(fn($c)=>$c[0]==='checkout.sessions.create'));
    }
    public function test_order_saves_billing_details_on_the_organization_and_catalog_prefills_them(): void {
        $this->getJson($this->path().'/catalog')->assertOk()->assertJsonPath('billing_details.saved',false)->assertJsonPath('billing_details.email_saved',false)->assertJsonPath('billing_details.company_name','Workspace')
            ->assertJsonPath('billing_details.email','owner@example.org')->assertJsonPath('billing_details.address.line1','')->assertJsonPath('billing_details.address.country','NL');
        $this->postJson($this->path().'/orders',$this->payload())->assertCreated();
        $org=$this->org->fresh();$this->assertSame('billing@example.org',$org->billing_email);
        $this->assertSame(['company_name'=>'Workspace','address'=>['line1'=>'Street 1','line2'=>null,'postal_code'=>'1000AA','city'=>'Amsterdam','country'=>'NL']],$org->billing_details);
        $this->assertStringNotContainsString('Street 1',DB::table('connect_organizations')->where('id',$org->id)->value('billing_details'));
        $this->getJson($this->path().'/catalog')->assertOk()->assertJsonPath('billing_details.saved',true)->assertJsonPath('billing_details.email_saved',true)->assertJsonPath('billing_details.email','billing@example.org')
            ->assertJsonPath('billing_details.address.line1','Street 1')->assertJsonPath('billing_details.address.postal_code','1000AA')->assertJsonPath('billing_details.address.city','Amsterdam');
        $this->getJson('/api/v1/connect/organizations/'.$this->org->id)->assertOk()->assertJsonPath('billing_details.address.city','Amsterdam')
            ->assertJsonMissingPath('organization.billing_email')->assertJsonMissingPath('organization.billing_details');
    }
    public function test_stripe_customer_is_only_updated_when_billing_details_change(): void {
        $this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated();
        $this->assertCount(1,$this->customerCalls('create'));$this->assertCount(0,$this->customerCalls('update'),'een nieuwe klant krijgt de gegevens al bij het aanmaken');
        $this->assertSame('Amsterdam',$this->customerCalls('create')[0][1][0]['address']['city']);
        $this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated();
        $this->assertCount(0,$this->customerCalls('update'),'ongewijzigde gegevens: klant niet bijwerken');
        $changed=$this->payload('invoice');$changed['billing_details']['address']['city']='Utrecht';
        $this->postJson($this->path().'/orders',$changed)->assertCreated();
        $updates=$this->customerCalls('update');$this->assertCount(1,$updates);$this->assertSame('cus_org',$updates[0][1][0]);$this->assertSame('Utrecht',$updates[0][1][1]['address']['city']);
        // Vanuit het Connect-account: ongewijzigd = niets, gewijzigd = bijwerken, terug naar eerdere gegevens = opnieuw bijwerken met een nieuwe sleutel.
        $this->putJson($this->path().'/details',$changed['billing_details'])->assertOk();
        $this->assertCount(1,$this->customerCalls('update'));
        $this->putJson($this->path().'/details',$this->details())->assertOk()->assertJsonPath('billing_details.address.city','Rotterdam')->assertJsonPath('billing_details.address.line2','t.a.v. inkoop')->assertJsonPath('billing_details.email','facturen@example.org');
        $this->putJson($this->path().'/details',$changed['billing_details'])->assertOk();
        $updates=$this->customerCalls('update');$this->assertCount(3,$updates);
        $this->assertSame(['name'=>'Workspace BV','email'=>'facturen@example.org','address'=>['line1'=>'Kade 2','line2'=>'t.a.v. inkoop','postal_code'=>'3011AA','city'=>'Rotterdam','country'=>'BE']],$updates[1][1][1]);
        $this->assertSame('',$updates[2][1][1]['address']['line2'],'een leeg adresregel 2 wordt bij de klant gewist');
        $this->assertCount(3,array_unique(array_map(fn($c)=>$c[1][2]['idempotency_key'],$updates)));
        // Oudere Connect-versies sturen alleen billing_email naar /account: ook dan volgt de klant.
        $this->putJson('/api/v1/connect/organizations/'.$this->org->id.'/account',['billing_email'=>'nieuw@example.org'])->assertOk();
        $updates=$this->customerCalls('update');$this->assertCount(4,$updates);$this->assertSame('nieuw@example.org',$updates[3][1][1]['email']);$this->assertSame('Utrecht',$updates[3][1][1]['address']['city']);
    }
    public function test_after_a_direct_checkout_the_account_details_are_sent_again(): void {
        // Stripe Checkout kan naam en adres van de klant overschrijven: daarna gaan de accountgegevens één keer opnieuw mee.
        $this->postJson($this->path().'/orders',$this->payload())->assertCreated();
        $this->assertCount(1,$this->customerCalls('create'));$this->assertNull($this->org->fresh()->stripe_customer_details_hash);
        $this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated();
        $updates=$this->customerCalls('update');$this->assertCount(1,$updates);$this->assertSame('Amsterdam',$updates[0][1][1]['address']['city']);
        $this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated();
        $this->assertCount(1,$this->customerCalls('update'),'daarna weer alleen bij een wijziging');
        // Komt de afgeronde Checkout later binnen, dan geldt hetzelfde (ook als er intussen al is bijgewerkt).
        $order=ConnectOrder::where('payment_method','direct')->firstOrFail();
        $this->billing->webhook('checkout.session.completed',(object)['id'=>'cs_connect','subscription'=>'sub_checkout','metadata'=>(object)['connect_order_id'=>$order->id],'payment_status'=>'paid']);
        $this->assertNull($this->org->fresh()->stripe_customer_details_hash);
        $this->putJson($this->path().'/details',$this->payload()['billing_details'])->assertOk();
        $this->assertCount(2,$this->customerCalls('update'));
    }
    public function test_a_retried_order_after_a_failed_checkout_sends_the_details_again(): void {
        $this->postJson($this->path().'/orders',$this->payload('invoice'))->assertCreated();
        $retry=$this->payload();$retry['billing_details']['address']['city']='Utrecht';
        $this->stripe->failCheckout=true;
        $this->postJson($this->path().'/orders',$retry)->assertUnprocessable();
        // Intussen in Connect terug naar de oude gegevens; daarna dezelfde bestelling opnieuw met Utrecht.
        $this->putJson($this->path().'/details',$this->payload()['billing_details'])->assertOk();
        $this->stripe->failCheckout=false;
        $this->postJson($this->path().'/orders',$retry)->assertCreated();
        $updates=$this->customerCalls('update');$this->assertCount(3,$updates);$this->assertSame('Utrecht',$updates[2][1][1]['address']['city']);
        // Een hergebruikte sleutel zou Stripe het eerdere antwoord laten herhalen zonder de klant bij te werken.
        $this->assertCount(3,array_unique(array_map(fn($c)=>$c[1][2]['idempotency_key'],$updates)));
        $this->assertDatabaseCount('connect_orders',2);$this->assertSame('Utrecht',$this->org->fresh()->billing_details['address']['city']);
    }
    public function test_a_refused_customer_update_is_a_neutral_error_and_changes_nothing(): void {
        $this->postJson($this->path().'/orders',$this->payload())->assertCreated();
        $this->stripe->failCustomerUpdate=true;
        $res=$this->putJson($this->path().'/details',$this->details())->assertUnprocessable()->assertJsonPath('message','De factuurgegevens konden niet worden bijgewerkt. Controleer je gegevens en probeer het opnieuw.');
        $this->assertStringNotContainsString('Stripe',$res->getContent());
        $org=$this->org->fresh();$this->assertSame('billing@example.org',$org->billing_email);$this->assertSame('Amsterdam',$org->billing_details['address']['city']);
        $this->stripe->failCustomerUpdate=false;
        $this->putJson($this->path().'/details',$this->details())->assertOk();
        $this->assertSame('Rotterdam',$this->org->fresh()->billing_details['address']['city']);$this->assertCount(2,$this->customerCalls('update'));
    }
    public function test_billing_details_are_validated_and_only_for_managers(): void {
        $this->putJson($this->path().'/details',$this->details(['country'=>'be']))->assertJsonValidationErrors('address.country');
        $this->putJson($this->path().'/details',['email'=>'geen-mailadres']+$this->details())->assertJsonValidationErrors('email');
        $this->putJson($this->path().'/details',['address'=>['line1'=>'Kade 2','extra'=>'x']]+$this->details())->assertUnprocessable();
        $this->putJson($this->path().'/details',$this->details())->assertOk();
        $this->assertSame([],$this->stripe->calls,'zonder klant bij de betaalprovider alleen opslaan');
        $member=User::create(['first_name'=>'Member','last_name'=>'Test','email'=>'member@example.org','password'=>bcrypt('Password1!'),'email_verified_at'=>now()]);
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$member->id,'role'=>'member','status'=>'active','email'=>$member->email,'first_name'=>'Member','last_name'=>'Test']);
        $this->actingAs($member,'sanctum');
        $this->putJson($this->path().'/details',$this->details(['city'=>'Gent']))->assertForbidden();
        $this->getJson($this->path().'/catalog')->assertForbidden();
        $access=$this->getJson('/api/v1/connect/access')->assertOk()->getContent();
        $this->assertStringNotContainsString('facturen@example.org',$access);$this->assertStringNotContainsString('Rotterdam',$access);
        $this->assertSame('Rotterdam',$this->org->fresh()->billing_details['address']['city']);
    }
    public function test_vat_amounts_follow_stripe_rounding(): void {
        $this->assertSame(['unit_term_cents'=>199,'unit_term_incl_cents'=>241,'excl_cents'=>1990,'vat_cents'=>418,'incl_cents'=>2408],ConnectBilling::amounts(199,1,10,false,'tax_rate',21));
        $this->assertSame(['unit_term_cents'=>199,'unit_term_incl_cents'=>241,'excl_cents'=>1992,'vat_cents'=>418,'incl_cents'=>2410],ConnectBilling::amounts(199,1,10,false,'gross_price',21));
        $this->assertSame(['unit_term_cents'=>242,'unit_term_incl_cents'=>242,'excl_cents'=>600,'vat_cents'=>126,'incl_cents'=>726],ConnectBilling::amounts(242,1,3,true,'tax_rate',21));
        $this->assertSame(['unit_term_cents'=>199,'unit_term_incl_cents'=>199,'excl_cents'=>199,'vat_cents'=>0,'incl_cents'=>199],ConnectBilling::amounts(199,1,1,false,'gross_price',0));
    }
    private function people(int $n): array { return array_map(fn($i)=>['first_name'=>'Lid','last_name'=>(string)$i,'email'=>"lid$i@example.org",'tags'=>['Klas 1']],range(1,$n)); }
    public function test_adding_more_than_the_free_seats_waits_for_payment_and_then_adds_everything(): void {
        // 2 seats, eigenaar gebruikt er 1: voor 3 nieuwe gebruikers moeten er 2 bij.
        $p=$this->postJson('/api/v1/connect/organizations/'.$this->org->id.'/pending-additions',['kind'=>'members','members'=>$this->people(3)])->assertCreated()->assertJsonPath('pending_addition.seats_needed',2);
        $id=$p->json('pending_addition.id');
        $this->getJson($this->path().'/catalog?pending='.$id)->assertOk()->assertJsonPath('pending_addition.row_count',3);
        $this->postJson($this->path().'/orders',['seats'=>1,'expected_total_incl_cents'=>242*12]+$this->payload()+['pending_addition_id'=>$id])->assertUnprocessable();
        $payload=$this->payload()+['pending_addition_id'=>$id];
        $res=$this->postJson($this->path().'/orders',$payload)->assertCreated();
        $order=ConnectOrder::findOrFail($res->json('order.id'));
        $this->assertSame($order->id,ConnectPendingAddition::find($id)->order_id);
        $this->assertSame(1,$this->org->members()->count());Mail::assertNothingSent();
        // A paid finalized snapshot can arrive before invoice.paid: status polling must keep waiting for capacity.
        $invoice=$this->invoice($order);$this->billing->webhook('invoice.finalized',$invoice);
        $this->getJson('/api/v1/connect/organizations/'.$this->org->id.'/pending-additions/'.$id)->assertOk()->assertJsonPath('pending_addition.status','pending');
        $this->assertSame(1,$this->org->members()->count());Mail::assertNothingSent();
        $this->billing->webhook('invoice.paid',$invoice);
        $this->assertSame(5,$this->org->fresh()->seat_limit);$this->assertSame(4,$this->org->members()->count());
        Mail::assertSent(\App\Mail\ConnectInvitation::class,3);
        $done=ConnectPendingAddition::find($id);$this->assertSame('completed',$done->status);$this->assertNull($done->rows);$this->assertSame(3,$done->result['added']);
        $this->postJson($this->path().'/orders',$payload)->assertCreated()->assertJsonPath('order.id',$order->id);
        $this->assertDatabaseCount('connect_orders',1);
        // Verlenging (volgende betaalde factuur) voegt niets opnieuw toe.
        $this->billing->webhook('invoice.paid',$this->invoice($order,'in_renewal'));
        $this->assertSame(4,$this->org->members()->count());Mail::assertSent(\App\Mail\ConnectInvitation::class,3);
        $this->getJson('/api/v1/connect/organizations/'.$this->org->id)->assertOk()->assertJsonPath('pending_additions.0.status','completed');
    }
    public function test_devices_waiting_on_payment_are_added_when_the_status_is_polled(): void {
        $devices=[['name'=>'iPad 1','udid'=>'AAAABBBB-0001'],['name'=>'iPad 2','udid'=>'AAAABBBB-0002']];
        $id=$this->postJson('/api/v1/connect/organizations/'.$this->org->id.'/pending-additions',['kind'=>'devices','devices'=>$devices])->assertCreated()->assertJsonPath('pending_addition.seats_needed',1)->json('pending_addition.id');
        $order=ConnectOrder::findOrFail($this->postJson($this->path().'/orders',$this->payload()+['pending_addition_id'=>$id])->assertCreated()->json('order.id'));
        $status='/api/v1/connect/organizations/'.$this->org->id.'/pending-additions/'.$id;
        $this->getJson($status)->assertOk()->assertJsonPath('pending_addition.status','pending');
        // Webhook-verwerking gemist: de betaalde factuur en seats zijn er al, de status-poll rondt af.
        app(\App\Services\ConnectLicensing::class)->add($this->org,['name'=>'Paid','seat_count'=>3,'monthly_price_cents'=>600,'currency'=>'EUR','starts_at'=>now()->subMinute(),'ends_at'=>now()->addYear(),'source_reference'=>'stripe-invoice:in_manual']);
        ConnectOrderInvoice::create(['order_id'=>$order->id,'stripe_invoice_id'=>'in_manual','status'=>'paid','total_cents'=>100,'currency'=>'EUR']);
        $this->getJson($status)->assertOk()->assertJsonPath('pending_addition.status','completed')->assertJsonPath('pending_addition.result.added',2);
        $this->assertSame(2,ConnectAllowedDevice::where('organization_id',$this->org->id)->count());
    }
    public function test_pending_additions_need_a_shortage_belong_to_the_organization_and_can_be_cancelled(): void {
        $base='/api/v1/connect/organizations/'.$this->org->id.'/pending-additions';
        $this->postJson($base,['kind'=>'members','members'=>$this->people(1)])->assertUnprocessable();
        $this->postJson($base,['kind'=>'members','members'=>[$this->people(1)[0],$this->people(1)[0],$this->people(2)[1]]])->assertUnprocessable();
        $id=$this->postJson($base,['kind'=>'members','members'=>$this->people(2)])->assertCreated()->json('pending_addition.id');
        $other=ConnectOrganization::create(['name'=>'Other','seat_limit'=>1,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear()]);
        $this->getJson('/api/v1/connect/organizations/'.$other->id.'/pending-additions/'.$id)->assertForbidden();
        // Een nieuwe aanvraag zonder bestelling vervangt de vorige.
        $second=$this->postJson($base,['kind'=>'members','members'=>$this->people(3)])->assertCreated()->json('pending_addition.id');
        $this->assertSame('cancelled',ConnectPendingAddition::find($id)->status);
        $this->deleteJson($base.'/'.$second)->assertOk()->assertJsonPath('pending_addition.status','cancelled');
        $this->postJson($this->path().'/orders',$this->payload()+['pending_addition_id'=>$second])->assertUnprocessable();
        $this->assertNull(ConnectPendingAddition::find($second)->rows);
    }
}
