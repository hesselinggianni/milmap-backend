<?php
namespace Tests\Feature;
use App\Models\{ConnectOrganization, ConnectMember, ConnectOrder, ConnectOrderInvoice, User};
use App\Services\ConnectBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail};
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Tests\TestCase;
class ConnectStripeFake extends StripeClient {
    public array $calls=[];
    public function __construct() {}
    public function __get($name) { return $this->endpoint($name); }
    private function endpoint(string $path) { return new class($this,$path) {
        public function __construct(private ConnectStripeFake $fake,private string $path) {}
        public function __get($name) { return new self($this->fake,$this->path.'.'.$name); }
        public function __call($method,$args) { return $this->fake->call($this->path.'.'.$method,$args); }
    }; }
    public function call(string $method,array $args) {
        $this->calls[]=[$method,$args];
        if($method==='prices.retrieve')return (object)['active'=>true,'type'=>'recurring','recurring'=>(object)['interval'=>'month','interval_count'=>1],'unit_amount'=>200,'product'=>'prod_connect','currency'=>'eur','tax_behavior'=>'unspecified'];
        if($method==='products.create')return (object)['id'=>'prod_org'];
        if($method==='customers.create'||$method==='customers.update')return (object)['id'=>'cus_org'];
        if($method==='checkout.sessions.create')return (object)['id'=>'cs_connect','url'=>'https://checkout.stripe.com/test-connect'];
        if($method==='subscriptions.create')return (object)['id'=>'sub_connect','latest_invoice'=>(object)['id'=>'in_first','status'=>'open','number'=>'INV-1','total'=>1200,'currency'=>'eur','hosted_invoice_url'=>'https://invoice.stripe.com/test','invoice_pdf'=>'https://files.stripe.com/test.pdf','lines'=>(object)['data'=>[(object)['period'=>(object)['start'=>now()->timestamp,'end'=>now()->addMonths(6)->timestamp]]]]]];
        if($method==='invoices.sendInvoice')return (object)['id'=>'in_first','status'=>'open','number'=>'INV-1','total'=>1200,'currency'=>'eur','hosted_invoice_url'=>'https://invoice.stripe.com/test','invoice_pdf'=>'https://files.stripe.com/test.pdf','lines'=>(object)['data'=>[(object)['period'=>(object)['start'=>now()->timestamp,'end'=>now()->addMonths(6)->timestamp]]]]];
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
    private function payload(string $method='direct',int $months=12): array { return ['request_id'=>(string)Str::uuid(),'seats'=>3,'term_months'=>$months,'payment_method'=>$method,'accepted'=>true,'expected_unit_monthly_cents'=>200,'expected_currency'=>'EUR','billing_details'=>['company_name'=>'Workspace','email'=>'billing@example.org','address'=>['line1'=>'Street 1','postal_code'=>'1000AA','city'=>'Amsterdam','country'=>'NL']]]; }
    private function path(): string { return '/api/v1/connect/organizations/'.$this->org->id.'/billing'; }
    private function invoice(ConnectOrder $order,string $id='in_paid'): object { return (object)['id'=>$id,'metadata'=>(object)['connect_order_id'=>$order->id],'subscription'=>'sub_connect','status'=>'paid','currency'=>'eur','total'=>$order->total_cents,'number'=>'INV-PAID','lines'=>(object)['data'=>[(object)['period'=>(object)['start'=>now()->timestamp,'end'=>now()->addMonths($order->term_months)->timestamp]]]],'status_transitions'=>(object)['paid_at'=>now()->timestamp]]; }
    public function test_direct_checkout_uses_server_price_selected_term_and_idempotent_order(): void {
        $data=$this->payload(); $res=$this->postJson($this->path().'/orders',$data)->assertCreated()->assertJsonPath('order.total_cents',7200);
        $this->postJson($this->path().'/orders',$data)->assertCreated()->assertJsonPath('order.id',$res->json('order.id'));
        $calls=array_values(array_filter($this->stripe->calls,fn($c)=>$c[0]==='checkout.sessions.create'));
        $this->assertCount(1,$calls);$params=$calls[0][1][0];
        $this->assertSame(2400,$params['line_items'][0]['price_data']['unit_amount']);$this->assertSame('year',$params['line_items'][0]['price_data']['recurring']['interval']);$this->assertSame('prod_org',$params['line_items'][0]['price_data']['product']);$this->assertSame(3,$params['line_items'][0]['quantity']);
        $this->assertSame(2,$this->org->fresh()->seat_limit);$this->assertDatabaseCount('connect_orders',1);
        $this->assertStringNotContainsString('billing@example.org',DB::table('connect_orders')->value('billing_details'));
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
}
