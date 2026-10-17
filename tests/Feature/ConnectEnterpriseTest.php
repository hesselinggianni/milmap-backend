<?php
namespace Tests\Feature;
use App\Models\ConnectOrganization;
use App\Models\ConnectMember;
use App\Models\ConnectDevice;
use App\Models\User;
use App\Services\ConnectRoster;
use App\Mail\ConnectInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class ConnectEnterpriseTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Mail::fake(); }
    private function user(string $email): User { return User::create(['first_name'=>'Test','last_name'=>'User','email'=>$email,'password'=>bcrypt('TestPassword1!'),'email_verified_at'=>now(),'trial_ends_at'=>now()->subDay()]); }
    private function organization(User $owner, int $seats=5): ConnectOrganization {
        $org=ConnectOrganization::create(['name'=>'Test school','seat_limit'=>$seats,'restrict_to_allowed_devices'=>false,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear()]);
        ConnectMember::create(['organization_id'=>$org->id,'user_id'=>$owner->id,'email'=>$owner->email,'first_name'=>'Test','last_name'=>'Owner','role'=>'owner','status'=>'active','tags'=>[]]); return $org;
    }
    private function row(string $email): array { return ['first_name'=>'Sam','last_name'=>'Jansen','email'=>$email,'tags'=>['Klas 1']]; }
    public function test_organizations_and_foreign_member_mutations_are_isolated(): void {
        $a=$this->user('a@test.nl');$b=$this->user('b@test.nl');$orgA=$this->organization($a);$orgB=$this->organization($b);
        $member=$orgB->members()->first();$this->actingAs($a,'sanctum');
        $this->getJson('/api/v1/connect/organizations')->assertOk()->assertJsonCount(1,'organizations')->assertJsonPath('organizations.0.id',$orgA->id);
        $this->getJson('/api/v1/connect/organizations/'.$orgB->id)->assertForbidden();
        $this->deleteJson("/api/v1/connect/organizations/$orgA->id/members/$member->id")->assertNotFound();
        $this->postJson("/api/v1/connect/organizations/$orgB->id/members",['members'=>[$this->row('new@test.nl')]])->assertForbidden();
    }
    public function test_capacity_and_duplicates_reject_entire_import(): void {
        $owner=$this->user('owner@test.nl');$org=$this->organization($owner,2);$this->actingAs($owner,'sanctum');
        $this->postJson("/api/v1/connect/organizations/$org->id/members",['members'=>[$this->row('one@test.nl'),$this->row('two@test.nl')]])->assertUnprocessable();
        $this->assertSame(1,$org->members()->count());Mail::assertNothingSent();
        $this->postJson("/api/v1/connect/organizations/$org->id/members",['members'=>[$this->row('ONE@test.nl'),$this->row('one@test.nl')]])->assertUnprocessable();
        $this->assertSame(1,$org->members()->count());
    }
    public function test_accept_requires_exact_recipient_and_grants_revocable_entitlements(): void {
        $owner=$this->user('owner@test.nl');$student=$this->user('student@test.nl');$wrong=$this->user('wrong@test.nl');$org=$this->organization($owner);
        app(ConnectRoster::class)->invite($org,[$this->row($student->email)]);
        $token='';Mail::assertSent(ConnectInvitation::class,function($mail) use (&$token) { $token=substr($mail->inviteUrl,strpos($mail->inviteUrl,'#token=')+7); return true; });
        $this->postJson('/api/v1/connect/invitations/preview',['token'=>$token])->assertOk()->assertJsonMissing(['email'=>$student->email]);
        $this->actingAs($wrong,'sanctum')->postJson('/api/v1/connect/invitations/accept',['token'=>$token])->assertForbidden();
        $this->actingAs($student,'sanctum')->postJson('/api/v1/connect/invitations/accept',['token'=>$token])->assertOk();
        $this->assertTrue($student->hasPremiumAccess());$this->assertSame('team',$student->plan());$this->assertTrue($student->hasFeature('missions','create'));
        $this->getJson("/api/v1/connect/organizations/$org->id")->assertForbidden();
        $member=$org->members()->where('user_id',$student->id)->first();
        $this->actingAs($owner,'sanctum')->deleteJson("/api/v1/connect/organizations/$org->id/members/$member->id")->assertNoContent();
        $this->assertFalse($student->hasPremiumAccess());$this->assertNotNull($student->fresh());
    }
    public function test_expired_license_stops_invites_and_access_but_not_admin_read(): void {
        $owner=$this->user('owner@test.nl');$org=$this->organization($owner);$org->update(['ends_at'=>now()->subSecond()]);$this->actingAs($owner,'sanctum');
        $this->getJson("/api/v1/connect/organizations/$org->id")->assertOk()->assertJsonPath('licensed',false);
        $this->postJson("/api/v1/connect/organizations/$org->id/members",['members'=>[$this->row('student@test.nl')]])->assertUnprocessable();
        $this->assertFalse($owner->hasPremiumAccess());
    }
    public function test_admin_cannot_promote_members_or_remove_owner(): void {
        $owner=$this->user('owner@test.nl');$admin=$this->user('admin@test.nl');$org=$this->organization($owner);
        ConnectMember::create(['organization_id'=>$org->id,'user_id'=>$admin->id,'email'=>$admin->email,'first_name'=>'Test','last_name'=>'Admin','role'=>'admin','status'=>'active']);
        $this->actingAs($admin,'sanctum');$row=$this->row('other@test.nl');$row['role']='admin';
        $this->postJson("/api/v1/connect/organizations/$org->id/members",['members'=>[$row]])->assertForbidden();
        $ownerMember=$org->members()->where('role','owner')->first();
        $this->deleteJson("/api/v1/connect/organizations/$org->id/members/$ownerMember->id")->assertForbidden();
    }
    public function test_device_registry_deduplicates_logins_and_revocation_is_scoped(): void {
        $owner=$this->user('owner@test.nl');$student=$this->user('student@test.nl');$outsider=$this->user('outsider@test.nl');$org=$this->organization($owner);
        ConnectMember::create(['organization_id'=>$org->id,'user_id'=>$student->id,'email'=>$student->email,'first_name'=>'Sam','last_name'=>'Jansen','role'=>'member','status'=>'active']);
        $installation=(string)Str::uuid();$request=\Illuminate\Http\Request::create('/');$request->headers->set('X-Milmap-Installation',$installation);$request->headers->set('User-Agent','Mozilla/5.0 (iPad) Safari');
        $token=$student->createToken('App',['user'])->accessToken;
        app(\App\Services\ConnectDeviceRegistry::class)->record($request,$token,true);
        app(\App\Services\ConnectDeviceRegistry::class)->record($request,$token,true);
        $this->assertSame(1,ConnectDevice::where('user_id',$student->id)->count());
        $device=ConnectDevice::where('user_id',$student->id)->first();
        $foreign=ConnectDevice::create(['user_id'=>$outsider->id,'installation_id'=>(string)Str::uuid(),'name'=>'Other','platform'=>'web','last_seen_at'=>now()]);
        $this->actingAs($owner,'sanctum');
        $this->deleteJson("/api/v1/connect/organizations/$org->id/devices/$foreign->id")->assertNotFound();
        $this->deleteJson("/api/v1/connect/organizations/$org->id/devices/$device->id")->assertNoContent();
        $this->assertFalse(DB::table('personal_access_tokens')->where('id',$token->id)->exists());$this->assertNotNull($device->fresh()->revoked_at);
    }
    public function test_web_logins_are_not_registered_as_devices(): void {
        $owner=$this->user('web-owner@test.nl'); $org=$this->organization($owner, 50);
        $registry=app(\App\Services\ConnectDeviceRegistry::class);
        $web=\Illuminate\Http\Request::create('/'); $web->headers->set('X-Milmap-Installation', (string) Str::uuid()); $web->headers->set('X-Milmap-Client', 'web');
        $registry->record($web, $owner->createToken('Web',['user'])->accessToken, true);
        $registry->record($web, $owner->createToken('Web',['user'])->accessToken);
        $this->assertSame(0, ConnectDevice::where('user_id',$owner->id)->count());
        // Browsersessie bezet geen apparaat: inloggen in de app blijft mogelijk.
        $app=\Illuminate\Http\Request::create('/'); $app->headers->set('X-Milmap-Installation', (string) Str::uuid()); $app->headers->set('X-Milmap-Client', 'ios'); $app->headers->set('X-Milmap-Device-Model', 'iPad16,6');
        $registry->record($app, $owner->createToken('App',['user'])->accessToken, true);
        $this->assertSame(1, $org->occupiedDevices());
        $this->assertSame('iPad Pro 13" (M4)', ConnectDevice::where('user_id',$owner->id)->value('name'));
    }
    public function test_each_account_has_at_most_one_connected_device(): void {
        $owner=$this->user('device-owner@test.nl'); $org=$this->organization($owner, 50);
        $registry=app(\App\Services\ConnectDeviceRegistry::class);
        for ($i=0; $i<1; $i++) {
            $r=\Illuminate\Http\Request::create('/'); $r->headers->set('X-Milmap-Installation', (string) Str::uuid());
            $registry->record($r, $owner->createToken('App',['user'],now()->addDays(7))->accessToken, true);
        }
        $this->assertSame(1, $org->occupiedDevices());
        $r=\Illuminate\Http\Request::create('/'); $r->headers->set('X-Milmap-Installation', (string) Str::uuid());
        try { $registry->record($r, $owner->createToken('App',['user'])->accessToken, true); $this->fail('Second device must be denied'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        $this->assertSame(1, ConnectDevice::where('user_id',$owner->id)->count());
    }
    public function test_device_capacity_is_shared_and_logout_frees_a_license(): void {
        $owner=$this->user('capacity-owner@test.nl'); $student=$this->user('capacity-student@test.nl'); $org=$this->organization($owner,3);
        ConnectMember::create(['organization_id'=>$org->id,'user_id'=>$student->id,'email'=>$student->email,'first_name'=>'Sam','last_name'=>'Jansen','role'=>'member','status'=>'active']);
        $registry=app(\App\Services\ConnectDeviceRegistry::class);
        $r=\Illuminate\Http\Request::create('/');$r->headers->set('X-Milmap-Installation',(string)Str::uuid());
        $token=$owner->createToken('App',['user'])->accessToken; $registry->record($r,$token,true);
        $r2=\Illuminate\Http\Request::create('/');$r2->headers->set('X-Milmap-Installation',(string)Str::uuid());
        $token2=$student->createToken('App',['user'])->accessToken;
        try { $registry->record($r2,$token2,true); $this->fail('Device capacity must be enforced'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        $token->delete(); $this->assertSame(0,$org->occupiedDevices());
        $registry->record($r2,$token2,true); $this->assertSame(1,$org->occupiedDevices());
    }
    public function test_fifty_licenses_cannot_admit_a_fifty_first_account(): void {
        $owner=$this->user('fifty-owner@test.nl');$org=$this->organization($owner,50);
        for($i=0;$i<49;$i++) ConnectMember::create(['organization_id'=>$org->id,'email'=>"member{$i}@test.nl",'first_name'=>'Sam','last_name'=>'Jansen','role'=>'member','status'=>'active']);
        $this->actingAs($owner,'sanctum');
        $this->postJson("/api/v1/connect/organizations/{$org->id}/members",['members'=>[$this->row('fiftyfirst@test.nl')]])->assertUnprocessable();
        $this->assertSame(50,$org->occupiedSeats());
    }

    public function test_customer_cannot_grant_themselves_unpaid_licenses(): void {
        $owner=$this->user('unpaid-owner@test.nl');$org=$this->organization($owner,50);$this->actingAs($owner,'sanctum');
        $this->postJson("/api/v1/connect/organizations/{$org->id}/license/upgrade",['additional_seats'=>50])->assertForbidden();
        $this->assertSame(50,$org->fresh()->seat_limit);
    }

    public function test_accounts_and_devices_each_use_a_license_and_login_keeps_one_session(): void {
        $owner=$this->user('lic-owner@test.nl'); $org=$this->organization($owner,5);
        $registry=app(\App\Services\ConnectDeviceRegistry::class);
        $install=(string)Str::uuid();
        $mk=function() use ($owner,$registry,$install){ $r=\Illuminate\Http\Request::create('/');$r->headers->set('X-Milmap-Installation',$install);
            $t=$owner->createToken('App',['user'])->accessToken; $registry->record($r,$t,true); return $t; };
        $first=$mk();
        $this->assertSame(2,$org->fresh()->usedLicenses());
        $second=$mk();
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::find($first->id));
        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::find($second->id));
        $this->assertSame(2,$org->fresh()->usedLicenses());
    }
    public function test_udid_device_login_is_optional_per_organization(): void {
        $owner=$this->user('udid-owner@test.nl'); $org=$this->organization($owner,5); $org->update(['device_login_mode'=>'binding']);
        \App\Models\ConnectAllowedDevice::create(['organization_id'=>$org->id,'name'=>'iPad','udid'=>'00008020-001234567890002E','platform'=>'ios']);
        $this->postJson('/api/v1/connect/device-login',['device_id'=>'00008020-001234567890002E'])->assertUnauthorized();
        $org->update(['device_login_mode'=>'udid']);
        $this->postJson('/api/v1/connect/device-login/status',['device_id'=>'00008020-001234567890002E'])->assertOk();
        $this->withHeaders(['X-Milmap-Installation'=>(string)Str::uuid()])->postJson('/api/v1/connect/device-login',['device_id'=>'00008020-001234567890002E'])->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('users',['email'=>'00008020-001234567890002e@connect.milmap.nl']);
        $this->postJson('/api/v1/connect/device-login',['device_id'=>'00008020-0012345678900000'])->assertUnauthorized();
    }
}
