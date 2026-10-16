<?php
namespace Tests\Feature;
use App\Models\{ConnectOrganization, ConnectMember, ConnectAllowedDevice, ConnectAuditEvent, Map, User};
use App\Services\{ConnectDeviceLogin, ConnectManagement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class ConnectManagementTest extends TestCase {
    use RefreshDatabase;
    private ConnectOrganization $org;
    private User $admin;
    protected function setUp(): void {
        parent::setUp(); Mail::fake();
        $this->org=ConnectOrganization::create(['name'=>'Organisation','status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addDays(10),'seat_limit'=>10]);
        $this->admin=User::create(['first_name'=>'Test','last_name'=>'User','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$this->admin->id,'email'=>$this->admin->email,'first_name'=>'Admin','last_name'=>'Owner','role'=>'owner','status'=>'active']);
    }
    private function device(): array {
        $d=ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'Tablet','udid'=>strtoupper(Str::random(8)).'-123456789']);
        $binding=app(ConnectDeviceLogin::class)->provision($d); auth()->forgetGuards();
        $res=$this->withHeaders(['Authorization'=>'','X-Milmap-Installation'=>(string)Str::uuid()])->postJson('/api/v1/connect/device-login',['device_binding'=>$binding])->assertOk();
        return [$d->fresh(),$res->json('token')];
    }
    private function token(string $token): self { auth()->forgetGuards(); return $this->withHeaders(['Authorization'=>'Bearer '.$token]); }
    private function manager(): self { auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'']); Sanctum::actingAs($this->admin); return $this; }
    public function test_server_context_and_bounded_offline_access(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['offline_hours'=>168,'support_name'=>'Servicedesk']]);
        $c=$this->token($token)->getJson('/api/v1/users/me')->assertOk()->json('connect_management');
        $this->assertTrue($c['managed']); $this->assertTrue($c['active']); $this->assertSame($this->org->id,$c['organization']['id']);
        $this->assertFalse($c['permissions']['export']); $this->assertFalse($c['capabilities']['delete_account']); $this->assertSame('Servicedesk',$c['support']['name']);
        $this->assertLessThanOrEqual($this->org->ends_at->timestamp,strtotime($c['offline_until']));
    }
    public function test_identity_billing_teams_and_deletion_are_blocked_via_api(): void {
        [$d,$token]=$this->device(); $this->token($token);
        foreach (['/api/v1/user/account/deletion/request-code','/api/v1/user/account/deletion','/api/v1/teams'] as $path) $this->postJson($path,[])->assertForbidden()->assertJsonPath('code','connect_policy_denied');
        $this->putJson('/api/v1/user/profile',['first_name'=>'Spoofed'])->assertForbidden();
        $this->getJson('/api/v1/billing/subscription')->assertForbidden(); $this->deleteJson('/api/v1/users/'.$d->user_id)->assertForbidden();
        $this->assertSame('Tablet',User::find($d->user_id)->first_name);
        $this->putJson('/api/v1/user/profile',['language'=>'nl'])->assertOk();
    }
    public function test_each_write_rechecks_permissions_and_member_overrides(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['create'=>false,'export'=>false]]);
        $this->token($token)->postJson('/api/v1/maps',['title'=>'Denied'])->assertForbidden();
        $this->postJson('/api/v1/connect/authorize-action',['permission'=>'export'])->assertForbidden();
        $this->org->update(['management_policy'=>['create'=>true,'export'=>true]]);
        ConnectMember::where('user_id',$d->user_id)->first()->update(['permissions'=>['create'=>true,'export'=>true]]);
        $res=$this->postJson('/api/v1/maps',['title'=>'Allowed'])->assertSuccessful(); $id=$res->json('id') ?? $res->json('data.id');
        $this->assertSame($this->org->id,Map::findOrFail($id)->connect_organization_id);
        $this->postJson('/api/v1/connect/authorize-action',['permission'=>'export'])->assertOk();
        $this->org->update(['management_policy'=>['edit'=>false]]);
        $this->putJson('/api/v1/maps/'.$id,['title'=>'Denied edit'])->assertForbidden(); $this->deleteJson('/api/v1/maps/'.$id)->assertForbidden();
        $this->assertDatabaseHas('connect_audit_events',['organization_id'=>$this->org->id,'action'=>'action.authorized']);
    }
    public function test_existing_public_links_obey_updated_policy(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['public_links'=>true]]); $this->token($token);
        $map=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        $res=$this->postJson('/api/v1/maps/'.$map->id.'/shares',[])->assertCreated(); $url='/api/v1/share/'.$res->json('token');
        $this->getJson($url)->assertOk(); $this->org->update(['management_policy'=>['public_links'=>false]]); $this->getJson($url)->assertForbidden();
    }
    public function test_transfer_retains_org_ownership_after_member_departure(): void {
        [$source]=$this->device(); [$target]=$this->device(); $work=Map::create(['owner_id'=>$source->user_id,'title'=>'Work','status'=>'active']);
        ConnectMember::where('user_id',$source->user_id)->update(['status'=>'removed']);
        $base='/api/v1/connect/organizations/'.$this->org->id;
        $this->manager()->getJson($base.'/resources')->assertOk()->assertJsonPath('maps.total',1);
        $this->postJson($base.'/resources/transfer',['from_user_id'=>$source->user_id,'to_user_id'=>$target->user_id])->assertOk()->assertJsonPath('transferred.maps',1);
        $this->assertSame((int)$target->user_id,(int)$work->fresh()->owner_id); $this->assertSame($this->org->id,$work->fresh()->connect_organization_id);
        $this->getJson($base.'/audit')->assertOk()->assertJsonFragment(['action'=>'resources.transferred']);
        $outsider=User::create(['first_name'=>'Test','last_name'=>'User','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]); $this->postJson($base.'/resources/transfer',['from_user_id'=>$target->user_id,'to_user_id'=>$outsider->id])->assertUnprocessable();
    }
    public function test_policy_and_history_require_tenant_manager(): void {
        [$d,$token]=$this->device(); $base='/api/v1/connect/organizations/'.$this->org->id;
        $this->token($token)->getJson($base.'/policy')->assertNotFound();
        $policy=ConnectManagement::DEFAULTS; $policy['offline_hours']=4;
        $this->manager()->putJson($base.'/policy',$policy)->assertOk()->assertJsonPath('policy.offline_hours',4);
        $event=ConnectAuditEvent::where('action','policy.updated')->firstOrFail();
        $this->assertSame($this->admin->id,$event->actor_user_id); $this->assertStringNotContainsString($token,json_encode($event));
        $other=ConnectOrganization::create(['name'=>'Foreign','seat_limit'=>1,'status'=>'active','starts_at'=>now(),'ends_at'=>now()->addDay()]); $this->getJson('/api/v1/connect/organizations/'.$other->id.'/resources')->assertNotFound();
        $this->putJson($base.'/policy',array_replace($policy,['offline_hours'=>999]))->assertUnprocessable();
    }
    public function test_org_data_blocks_account_cascade_deletion(): void {
        [$d,$token]=$this->device(); $this->token($token); Map::create(['owner_id'=>$d->user_id,'title'=>'Retain','status'=>'active']);
        try { User::findOrFail($d->user_id)->delete(); $this->fail('Deletion should be blocked'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $this->assertDatabaseHas('users',['id'=>$d->user_id]);
    }
}
