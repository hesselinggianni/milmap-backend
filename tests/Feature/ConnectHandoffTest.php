<?php
namespace Tests\Feature;
use App\Models\{User, ConnectOrganization, ConnectMember};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
class ConnectHandoffTest extends TestCase {
    use RefreshDatabase;
    public function test_checkout_handoff_is_single_use_and_bounds_offline_lease_by_new_token(): void {
        Mail::fake();
        $owner=User::create(['first_name'=>'Owner','last_name'=>'Test','email'=>'handoff@example.org','password'=>bcrypt('TestPassword1!'),'email_verified_at'=>now()]);
        $org=ConnectOrganization::create(['name'=>'Workspace','seat_limit'=>2,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear()]);
        ConnectMember::create(['organization_id'=>$org->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active','email'=>$owner->email,'first_name'=>'Owner','last_name'=>'Test']);
        $session=$owner->createToken('manager',['user'],now()->addDays(7))->plainTextToken;
        $code=$this->withToken($session)->postJson('/api/v1/auth/handoff')->assertOk()->json('token');
        auth()->forgetGuards();
        $response=$this->withHeaders(['Authorization'=>''])->postJson('/api/v1/auth/handoff/exchange',['token'=>$code])->assertOk();
        $this->assertSame($owner->id,$response->json('user.id'));
        $this->assertLessThanOrEqual(now()->addHour()->timestamp,strtotime($response->json('user.connect_management.offline_until')));
        auth()->forgetGuards();
        $this->withHeaders(['Authorization'=>''])->postJson('/api/v1/auth/handoff/exchange',['token'=>$code])->assertUnprocessable();
    }
}
