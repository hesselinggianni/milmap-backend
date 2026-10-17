<?php
namespace Tests\Feature;

use App\Models\{ConnectAllowedDevice, ConnectMember, ConnectOrganization, Map, Mission, RouteMap, User};
use App\Services\ConnectDeviceLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountResetAndNatThrottleTest extends TestCase
{
    use RefreshDatabase;
    private ConnectOrganization $org;
    private User $owner;

    protected function setUp(): void {
        parent::setUp(); Mail::fake();
        $this->org = ConnectOrganization::create(['name'=>'School','status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear(),'seat_limit'=>2000]);
        $this->owner = $this->user();
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$this->owner->id,'email'=>$this->owner->email,'first_name'=>'Admin','last_name'=>'Owner','role'=>'owner','status'=>'active']);
    }
    private function user(): User {
        return User::create(['first_name'=>'Test','last_name'=>'User','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
    }
    private function device(): array {
        $d = ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'iPad','udid'=>strtoupper(Str::random(8)).'-123456789']);
        $binding = app(ConnectDeviceLogin::class)->provision($d);
        return [$d->fresh(), $binding];
    }
    private function guest(?string $installation = null): self {
        auth()->forgetGuards();
        return $this->withHeaders(['Authorization'=>'','X-Milmap-Installation'=>$installation ?? (string) Str::uuid()]);
    }

    public function test_public_routes_no_longer_share_one_bucket(): void {
        // Tegels/app-events vulden vroeger het budget van de login: nu eigen emmer per route.
        for ($i = 0; $i < 30; $i++) $this->guest('same-installation-1')->getJson('/api/v1/app-version');
        $this->guest('same-installation-1')->getJson('/api/v1/app-version')->assertStatus(429);
        $this->guest('same-installation-1')->postJson('/api/v1/check-email', ['email'=>'a@example.org'])->assertOk();
    }

    public function test_many_installations_behind_one_ip_are_not_locked_out(): void {
        // 20/min per installatie; 60 apparaten op één school-IP mogen allemaal.
        for ($i = 0; $i < 60; $i++) {
            $this->guest()->postJson('/api/v1/check-email', ['email'=>"leerling$i@example.org"])->assertOk();
        }
        // Eén installatie blijft wel begrensd.
        for ($i = 0; $i < 20; $i++) $this->guest('one-installation-1')->postJson('/api/v1/check-email', ['email'=>'x@example.org']);
        $this->guest('one-installation-1')->postJson('/api/v1/check-email', ['email'=>'x@example.org'])->assertStatus(429);
    }

    public function test_ip_ceiling_still_bounds_installation_rotation(): void {
        config(['services.throttle.nat_factor' => 2]);
        for ($i = 0; $i < 40; $i++) $this->guest()->postJson('/api/v1/check-email', ['email'=>'r@example.org'])->assertOk();
        $this->guest()->postJson('/api/v1/check-email', ['email'=>'r@example.org'])->assertStatus(429);
    }

    public function test_class_of_devices_on_one_ip_can_log_in_again_after_logout(): void {
        $devices = []; for ($i = 0; $i < 40; $i++) $devices[] = $this->device();
        foreach ($devices as [$d, $binding]) {
            $this->guest()->postJson('/api/v1/connect/device-login/status', ['device_binding'=>$binding])->assertOk();
            $token = $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk()->json('token');
            auth()->forgetGuards();
            $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/v1/logout')->assertOk();
            $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk();
        }
        // Per apparaat blijft het begrensd.
        [, $binding] = $devices[0];
        for ($i = 0; $i < 30; $i++) $this->guest()->postJson('/api/v1/connect/device-login/status', ['device_binding'=>$binding]);
        $this->guest()->postJson('/api/v1/connect/device-login/status', ['device_binding'=>$binding])->assertStatus(429);
    }

    public function test_logout_keeps_device_account_data(): void {
        [$d, $binding] = $this->device();
        $token = $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk()->json('token');
        Map::create(['owner_id'=>$d->user_id,'title'=>'Mijn kaart','status'=>'active']);
        auth()->forgetGuards();
        $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/v1/logout')->assertOk();
        $user = $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk()->json('user.id');
        $this->assertSame((int) $d->user_id, (int) $user);
        $this->assertSame(1, Map::where('owner_id', $d->user_id)->count());
    }

    public function test_user_can_reset_own_account(): void {
        $user = $this->user(); $other = $this->user();
        $mine = Map::create(['owner_id'=>$user->id,'title'=>'Mijn kaart','status'=>'active']);
        $trashed = Map::create(['owner_id'=>$user->id,'title'=>'Prullenbak','status'=>'active']); $trashed->delete();
        $theirs = Map::create(['owner_id'=>$other->id,'title'=>'Andermans kaart','status'=>'active']);
        // Eigen route op andermans kaart: van de gebruiker, dus weg; de kaart zelf blijft.
        RouteMap::create(['owner_id'=>$user->id,'map_id'=>$theirs->id,'title'=>'Route']);
        Mission::create(['owner_id'=>$user->id,'name'=>'Missie']);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/account/reset', [])->assertUnprocessable();
        $this->assertDatabaseHas('maps', ['id'=>$mine->id]);
        $this->postJson('/api/v1/user/account/reset', ['confirm'=>'RESET'])->assertOk()->assertJsonPath('deleted.maps', 2);

        $this->assertSame(0, Map::withTrashed()->where('owner_id', $user->id)->count());
        $this->assertSame(0, RouteMap::withTrashed()->where('owner_id', $user->id)->count());
        $this->assertSame(0, Mission::withTrashed()->where('owner_id', $user->id)->count());
        $this->assertDatabaseHas('maps', ['id'=>$theirs->id]);
        $this->assertDatabaseHas('users', ['id'=>$user->id]);
        $this->assertNotNull($user->fresh()->data_reset_at);
    }

    public function test_connect_policy_can_disable_self_reset(): void {
        [$d, $binding] = $this->device();
        $token = $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk()->json('token');
        $this->org->update(['management_policy'=>['reset_account'=>false]]);
        auth()->forgetGuards();
        $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/v1/user/account/reset', ['confirm'=>'RESET'])
            ->assertForbidden()->assertJsonPath('permission', 'reset_account');
        $this->org->update(['management_policy'=>['reset_account'=>true]]);
        auth()->forgetGuards();
        $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/v1/user/account/reset', ['confirm'=>'RESET'])->assertOk();
    }

    public function test_connect_admin_resets_device_account_completely(): void {
        [$d, $binding] = $this->device();
        Map::create(['owner_id'=>$d->user_id,'title'=>'Leerling','status'=>'active']);
        $member = ConnectMember::where('user_id', $d->user_id)->firstOrFail();
        $base = '/api/v1/connect/organizations/'.$this->org->id;

        // Een gewone gebruiker mag niemand resetten.
        $token = $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk()->json('token');
        auth()->forgetGuards();
        $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson($base.'/allowed-devices/'.$d->id.'/reset')->assertStatus(403);

        auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'']); Sanctum::actingAs($this->owner);
        $this->postJson($base.'/allowed-devices/'.$d->id.'/reset')->assertOk()->assertJsonPath('scope', 'account')->assertJsonPath('deleted.maps', 1);
        $this->assertSame(0, Map::where('owner_id', $d->user_id)->count());
        Map::create(['owner_id'=>$d->user_id,'title'=>'Opnieuw','status'=>'active']);
        $this->postJson($base.'/members/'.$member->id.'/reset')->assertOk()->assertJsonPath('scope', 'account');
        $this->assertSame(0, Map::where('owner_id', $d->user_id)->count());
        $this->assertDatabaseHas('connect_audit_events', ['organization_id'=>$this->org->id, 'action'=>'account.reset']);
        // Koppeling en login blijven werken.
        $this->guest()->postJson('/api/v1/connect/device-login', ['device_binding'=>$binding])->assertOk();
        // De eigenaar is niet te resetten vanuit Connect.
        auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'']); Sanctum::actingAs($this->owner);
        $this->postJson($base.'/members/'.ConnectMember::where('user_id', $this->owner->id)->value('id').'/reset')->assertStatus(403);
    }

    public function test_connect_admin_reset_of_personal_account_only_touches_organization_data(): void {
        $person = $this->user();
        $member = ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$person->id,'email'=>$person->email,'first_name'=>'Piet','last_name'=>'Lid','role'=>'member','status'=>'active']);
        $private = Map::create(['owner_id'=>$person->id,'title'=>'Privé','status'=>'active']);
        DB::table('maps')->where('id', $private->id)->update(['connect_organization_id'=>null]);
        $work = Map::create(['owner_id'=>$person->id,'title'=>'Werk','status'=>'active']);
        DB::table('maps')->where('id', $work->id)->update(['connect_organization_id'=>$this->org->id]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/connect/organizations/'.$this->org->id.'/members/'.$member->id.'/reset')
            ->assertOk()->assertJsonPath('scope', 'organization')->assertJsonPath('deleted.maps', 1);
        $this->assertDatabaseHas('maps', ['id'=>$private->id]);
        $this->assertDatabaseMissing('maps', ['id'=>$work->id]);
    }
}
