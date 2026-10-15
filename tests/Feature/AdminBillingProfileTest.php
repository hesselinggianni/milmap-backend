<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\{Str, Facades\DB};
use Stripe\ApiRequestor;
use Stripe\HttpClient\{ClientInterface, CurlClient};
use Tests\TestCase;

class AdminBillingProfileTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); config(['billing.stripe_secret'=>'sk_test_not_a_real_key']); }
    protected function tearDown(): void { ApiRequestor::setHttpClient(CurlClient::instance()); parent::tearDown(); }
    private function user(bool $admin=false): User {
        $user=User::create(['first_name'=>'Test','last_name'=>'Klant','email'=>Str::uuid().'@example.test','password'=>bcrypt('test'),'email_verified_at'=>now()]);
        $user->forceFill(['is_admin'=>$admin])->save();return $user;
    }
    private function login(User $user): void { app('auth')->forgetGuards();$this->withToken($user->createToken('test',['admin'],now()->addHour())->plainTextToken); }
    private function payload(): array { return ['name'=>'Voorbeeld BV','email'=>'factuur@example.test','phone'=>'+31201234567','address'=>['line1'=>'Teststraat 12','line2'=>'','postal_code'=>'1000 AA','city'=>'Amsterdam','state'=>'','country'=>'NL'],'request_id'=>(string)Str::uuid()]; }
    private function stripe(callable $callback): void {
        $http=\Mockery::mock(ClientInterface::class);$http->shouldReceive('request')->once()->andReturnUsing($callback);ApiRequestor::setHttpClient($http);
    }
    public function test_update_syncs_only_the_linked_customer_and_keeps_local_details_private(): void {
        $admin=$this->user(true);$customer=$this->user();$customer->forceFill(['stripe_id'=>'cus_target'])->save();$email=$customer->email;$payload=$this->payload();$this->login($admin);
        $this->stripe(function($method,$url,$headers,$params)use($payload){$this->assertSame('post',$method);$this->assertSame('https://api.stripe.com/v1/customers/cus_target',$url);$this->assertSame($payload['address'],$params['address']);$this->assertSame($payload['email'],$params['email']);$this->assertTrue((bool)array_filter($headers,fn($h)=>str_contains($h,'Idempotency-Key:')));return [json_encode(['id'=>'cus_target','object'=>'customer']+$params),200,[]];});
        $this->putJson("/api/v1/admin/users/{$customer->id}/billing-profile",$payload+['stripe_id'=>'cus_attacker'])->assertOk()->assertJsonPath('details.name','Voorbeeld BV')->assertJsonPath('stripe_customer_id','cus_target');
        $customer->refresh();$this->assertSame($email,$customer->email);$this->assertSame('Voorbeeld BV',$customer->billing_details['name']);$this->assertStringNotContainsString('Voorbeeld BV',DB::table('users')->where('id',$customer->id)->value('billing_details'));$this->assertArrayNotHasKey('billing_details',$customer->toArray());$this->assertArrayNotHasKey('billing_synced_at',$customer->toArray());
    }
    public function test_stripe_failure_does_not_store_unsynchronized_changes(): void {
        $admin=$this->user(true);$customer=$this->user();$customer->forceFill(['stripe_id'=>'cus_target','billing_details'=>['name'=>'Oud']])->save();$this->login($admin);
        $this->stripe(fn()=>[json_encode(['error'=>['type'=>'invalid_request_error','message'=>'Invalid address']]),400,[]]);
        $this->putJson("/api/v1/admin/users/{$customer->id}/billing-profile",$this->payload())->assertUnprocessable();$this->assertSame(['name'=>'Oud'],$customer->fresh()->billing_details);$this->assertNull($customer->fresh()->billing_synced_at);
    }
    public function test_unlinked_customer_can_be_created_and_linked_on_explicit_save(): void {
        $admin=$this->user(true);$customer=$this->user();$this->login($admin);
        $this->stripe(function($method,$url,$headers,$params)use($customer){$this->assertSame('https://api.stripe.com/v1/customers',$url);$this->assertSame((string)$customer->id,$params['metadata']['user_id']);return [json_encode(['id'=>'cus_new','object'=>'customer']+$params),200,[]];});
        $this->putJson("/api/v1/admin/users/{$customer->id}/billing-profile",$this->payload())->assertOk()->assertJsonPath('stripe_customer_id','cus_new');$this->assertSame('cus_new',$customer->fresh()->stripe_id);
    }
    public function test_read_loads_latest_details_from_stripe(): void {
        $admin=$this->user(true);$customer=$this->user();$customer->forceFill(['stripe_id'=>'cus_target','billing_details'=>['name'=>'Oud']])->save();$this->login($admin);
        $this->stripe(fn()=>[json_encode(['id'=>'cus_target','object'=>'customer','name'=>'Gewijzigd in Stripe','email'=>'stripe@example.test','address'=>null]),200,[]]);
        $this->getJson("/api/v1/admin/users/{$customer->id}/billing-profile")->assertOk()->assertJsonPath('details.name','Gewijzigd in Stripe')->assertJsonPath('details.address.line1','');
    }
    public function test_access_validation_and_missing_stripe_configuration_are_enforced(): void {
        $customer=$this->user();$this->login($customer);$url="/api/v1/admin/users/{$customer->id}/billing-profile";
        $this->getJson($url)->assertForbidden();$this->putJson($url,$this->payload())->assertForbidden();
        $this->login($this->user(true));$payload=$this->payload();$payload['email']='invalid';$this->putJson($url,$payload)->assertUnprocessable();
        config(['billing.stripe_secret'=>null]);$this->putJson($url,$this->payload())->assertStatus(503);$this->assertNull($customer->fresh()->billing_details);
    }
}
