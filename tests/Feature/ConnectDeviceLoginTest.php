<?php
namespace Tests\Feature;

use App\Models\{ConnectAllowedDevice, ConnectDevice, ConnectMember, ConnectOrganization, User, Map};
use App\Services\ConnectDeviceLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectDeviceLoginTest extends TestCase
{
    use RefreshDatabase;
    private ConnectOrganization $org;
    private ConnectAllowedDevice $device;
    private string $mdm = 'server-only-mdm-token';
    private string $installation;
    protected function setUp(): void {
        parent::setUp(); Mail::fake();
        $this->org = ConnectOrganization::create(['name'=>'Workspace','seat_limit'=>5,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear(),'restrict_to_allowed_devices'=>true]);
        $this->org->forceFill(['mdm_token_hash'=>hash('sha256',$this->mdm)])->save();
        $this->device = ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'iPad','udid'=>'18D2D99B-123456789']);
        $this->installation = (string) Str::uuid();
    }
    private function binding(): string { return app(ConnectDeviceLogin::class)->provision($this->device); }
    private function loginDevice(string $secret) {
        auth()->forgetGuards();
        return $this->withHeaders(['Authorization'=>'','X-Milmap-Installation'=>$this->installation,'X-Milmap-Device-Id'=>'CLIENT-SPOOF'])
            ->postJson('/api/v1/connect/device-login',['device_binding'=>$secret,'email'=>'attacker@test.nl','udid'=>'SPOOFED']);
    }
    private function asToken(string $token): self {
        auth()->forgetGuards();
        return $this->withHeaders(['Authorization'=>'Bearer '.$token,'X-Milmap-Installation'=>$this->installation]);
    }
    public function test_mdm_only_provisions_registered_device_and_returns_secret_once(): void {
        $path='/api/v1/connect/mdm/devices/'.$this->device->udid.'/binding';
        $this->postJson($path)->assertUnauthorized();
        $secret=$this->withToken($this->mdm)->postJson($path)->assertOk()->assertHeader('Cache-Control','max-age=0, no-store, private')->json('device_binding');
        $this->assertSame(64,strlen($secret));
        $this->assertSame(hash('sha256',$secret),$this->device->fresh()->binding_hash);
        $this->assertArrayNotHasKey('binding_hash',$this->device->fresh()->toArray());
        $this->withToken($this->mdm)->postJson('/api/v1/connect/mdm/devices/UNKNOWN-123/binding')->assertNotFound();
    }
    public function test_udid_email_and_organization_token_cannot_authenticate_device(): void {
        $this->binding();
        $this->postJson('/api/v1/connect/device-login',['udid'=>$this->device->udid,'email'=>'18D2D99B@connect.milmap.nl'])->assertUnauthorized();
        $this->loginDevice($this->mdm)->assertUnauthorized();
        $this->loginDevice(str_repeat('x',64))->assertUnauthorized();
        $this->assertSame(0,DB::table('personal_access_tokens')->count());
    }
    public function test_verified_login_uses_server_account_and_trusted_udid(): void {
        $secret=$this->binding();
        $this->postJson('/api/v1/connect/device-login/status',['device_binding'=>$secret])->assertOk()->assertJsonPath('managed',true);
        $res=$this->loginDevice($secret)->assertOk()->assertJsonPath('user.email','18D2D99B@connect.milmap.nl');
        $this->assertTrue(User::find($res->json('user.id'))->hasPremiumAccess());
        $this->assertSame($this->device->udid,ConnectDevice::first()->vendor_id);
        $this->assertSame($this->org->id,ConnectDevice::first()->organization_id);
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertOk();
        Mail::assertNothingSent();
    }
    public function test_device_account_cannot_set_or_change_password(): void {
        $res = $this->loginDevice($this->binding())->assertOk();
        $user = User::findOrFail($res->json('user.id'));
        $original = $user->password;
        $this->asToken($res->json('token'))->putJson('/api/v1/user/password', [
            'password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!',
        ])->assertForbidden();
        $this->assertSame($original, $user->fresh()->password);
        $this->assertNull($user->fresh()->password_set_at);
    }
    public function test_device_account_cannot_reset_password(): void {
        $res = $this->loginDevice($this->binding())->assertOk();
        $user = User::findOrFail($res->json('user.id'));
        $original = $user->password;
        $this->postJson('/api/v1/password/reset-link', ['email' => $user->email])->assertOk();
        Mail::assertNothingSent();
        $token = \Illuminate\Support\Facades\Password::createToken($user);
        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!',
        ])->assertForbidden();
        $this->assertSame($original, $user->fresh()->password);
        $this->assertNull($user->fresh()->password_set_at);
    }
    public function test_android_device_uses_tenant_specific_identity_and_binding(): void {
        $device=ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'Android tablet','platform'=>'android','udid'=>'ANDROIDASSET12345']);
        $secret=app(ConnectDeviceLogin::class)->provision($device);
        $res=$this->loginDevice($secret)->assertOk()->assertJsonPath('user.connect_device_account',true);
        $this->assertStringEndsWith('@connect.milmap.nl',$res->json('user.email'));
        $this->assertStringNotContainsString('ANDROIDASSET12345',$res->json('user.email'));
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertOk();
        app(ConnectDeviceLogin::class)->revoke($device->fresh());
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
    }
    public function test_missing_installation_rolls_back_session(): void {
        $secret=$this->binding();
        $this->postJson('/api/v1/connect/device-login',['device_binding'=>$secret])->assertUnprocessable();
        $this->assertSame(0,DB::table('personal_access_tokens')->count());
    }
    public function test_prefix_collision_and_preexisting_accounts_never_merge(): void {
        $this->binding();
        $other=ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'Other','udid'=>'18D2D99B-DIFFERENT']);
        try { app(ConnectDeviceLogin::class)->provision($other); $this->fail('Collision accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $this->assertNull($other->fresh()->user_id);
        $this->assertSame(1,User::count());
    }
    public function test_rotation_invalidates_secret_and_sessions_but_retains_account(): void {
        $secret=$this->binding();$res=$this->loginDevice($secret)->assertOk();
        $next=$this->binding();
        $this->assertSame(1,User::count());
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->loginDevice($secret)->assertUnauthorized();
        $this->loginDevice($next)->assertOk()->assertJsonPath('user.id',$res->json('user.id'));
    }
    public function test_blocking_device_revokes_login_and_active_session(): void {
        $secret=$this->binding();$res=$this->loginDevice($secret)->assertOk();
        $owner=User::create(['first_name'=>'Owner','email'=>'owner@test.nl','password'=>bcrypt('password')]);
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$owner->id,'email'=>$owner->email,'first_name'=>'Owner','last_name'=>'Test','status'=>'active','role'=>'owner']);
        $device=ConnectDevice::first();
        $ownerToken=$owner->createToken('Portal',['user'])->plainTextToken;
        $this->asToken($ownerToken)->deleteJson("/api/v1/connect/organizations/{$this->org->id}/devices/{$device->id}")->assertNoContent();
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->loginDevice($secret)->assertUnauthorized();
        $this->assertNull($this->device->fresh()->binding_hash);
    }
    public function test_allowlist_deletion_revokes_existing_session_and_binding(): void {
        $secret=$this->binding();$res=$this->loginDevice($secret)->assertOk();
        $this->withToken($this->mdm)->deleteJson('/api/v1/connect/mdm/devices/'.$this->device->udid)->assertNoContent();
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->loginDevice($secret)->assertUnauthorized();
    }
    public function test_expired_license_or_removed_membership_fails_closed(): void {
        $secret=$this->binding();$res=$this->loginDevice($secret)->assertOk();
        $this->org->update(['status'=>'blocked']);
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->loginDevice($secret)->assertUnauthorized();
        $this->org->update(['status'=>'active']);
        ConnectMember::where('user_id',$this->device->fresh()->user_id)->update(['status'=>'removed']);
        $this->asToken($res->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->loginDevice($secret)->assertUnauthorized();
    }
    public function test_device_accounts_have_isolated_cloud_maps_and_share_management(): void {
        $this->org->update(['management_policy' => ['public_links' => true]]);
        $a=$this->loginDevice($this->binding())->assertOk();
        $other=ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'Other','udid'=>'BBBBBBBB-987654321']);
        $b=$this->loginDevice(app(ConnectDeviceLogin::class)->provision($other))->assertOk();
        $map=Map::create(['id'=>(string)Str::uuid(),'owner_id'=>$a->json('user.id'),'title'=>'Private cloud map','status'=>'active']);
        $this->asToken($a->json('token'))->getJson('/api/v1/maps')->assertOk()->assertJsonCount(1);
        $share=$this->asToken($a->json('token'))->postJson("/api/v1/maps/{$map->id}/shares",['title'=>'Shared'])->assertSuccessful();
        $this->asToken($b->json('token'))->getJson('/api/v1/maps')->assertOk()->assertJsonCount(0);
        $this->asToken($b->json('token'))->getJson('/api/v1/share/'.$share->json('token'))->assertOk();
        $this->asToken($b->json('token'))->getJson('/api/v1/share/invalid-token')->assertNotFound();
        $this->asToken($b->json('token'))->deleteJson("/api/v1/maps/{$map->id}/shares/".$share->json('id'))->assertNotFound();
        $this->asToken($b->json('token'))->getJson("/api/v1/maps/{$map->id}/shares")->assertNotFound();
        $this->asToken($b->json('token'))->postJson("/api/v1/maps/{$map->id}/shares",[])->assertNotFound();
        $this->asToken($b->json('token'))->putJson("/api/v1/maps/{$map->id}",['title'=>'Stolen'])->assertForbidden();
        $this->assertDatabaseHas('maps',['id'=>$map->id,'title'=>'Private cloud map']);
    }
    public function test_device_user_cannot_bypass_binding_via_normal_login_or_unbound_token(): void {
        $this->binding();
        $user=User::findOrFail($this->device->fresh()->user_id);
        $user->update(['password'=>bcrypt('ChangedPassword1!')]);
        $this->postJson('/api/v1/login',['email'=>$user->email,'password'=>'ChangedPassword1!'])->assertForbidden();
        $this->asToken($user->createToken('Other',['user'])->plainTextToken)->getJson('/api/v1/users/me')->assertUnauthorized();
    }
    public function test_enterprise_device_needs_no_personal_subscription_or_trial_and_has_all_package_features(): void {
        foreach (User::PREMIUM_FEATURES as $feature) {
            \App\Models\PlanPermission::updateOrCreate(['plan'=>'team','module'=>$feature],
                ['can_view'=>false,'can_create'=>false,'can_update'=>false,'can_delete'=>false]);
        }
        $secret=$this->binding();
        $user=User::findOrFail($this->device->fresh()->user_id);
        $user->update(['trial_ends_at'=>now()->subDay()]);
        $this->assertFalse($user->subscribed());
        $this->assertFalse($user->onAppTrial());
        $response=$this->loginDevice($secret)->assertOk()
            ->assertJsonPath('user.premium.subscribed',false)
            ->assertJsonPath('user.premium.on_trial',false)
            ->assertJsonPath('user.premium.has_premium',true)
            ->assertJsonPath('user.premium.organization.via','enterprise')
            ->assertJsonPath('user.premium.map_limit',null)
            ->assertJsonPath('user.premium.mission_limit',null);
        foreach (User::PREMIUM_FEATURES as $feature) {
            $response->assertJsonPath('user.premium.features.'.$feature,true);
            foreach (['view','create','update','delete'] as $action) $this->assertTrue($user->hasFeature($feature,$action));
        }
        $this->org->update(['status'=>'blocked']);
        $this->assertFalse($user->hasPremiumAccess());
        $this->asToken($response->json('token'))->getJson('/api/v1/users/me')->assertUnauthorized();
    }
    public function test_normal_login_remains_available(): void {
        $user=User::create(['first_name'=>'Normal','email'=>'normal@test.nl','password'=>bcrypt('NormalPassword1!'),'email_verified_at'=>now()]);
        $this->postJson('/api/v1/login',['email'=>$user->email,'password'=>'NormalPassword1!'])->assertOk()->assertJsonPath('user.id',$user->id);
    }
}
