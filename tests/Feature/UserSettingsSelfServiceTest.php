<?php
namespace Tests\Feature;
use App\Models\{ConnectOrganization, ConnectAllowedDevice, User};
use App\Services\ConnectDeviceLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
// Kaartweergave-voorkeuren (locatieformaat, 2D/3D, …) via PUT /user/settings: ook voor
// Connect-accounts, waar PUT /users/{id} als accountbeheer geblokkeerd blijft.
class UserSettingsSelfServiceTest extends TestCase {
    use RefreshDatabase;
    private function deviceToken(array $policy = []): array {
        Mail::fake();
        $org=ConnectOrganization::create(['name'=>'Organisation','status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addDays(10),'seat_limit'=>10,'management_policy'=>$policy]);
        $d=ConnectAllowedDevice::create(['organization_id'=>$org->id,'name'=>'Tablet','platform'=>'ios','udid'=>strtoupper(Str::random(8)).'-123456789']);
        $binding=app(ConnectDeviceLogin::class)->provision($d); auth()->forgetGuards();
        $res=$this->withHeaders(['Authorization'=>'','X-Milmap-Installation'=>(string)Str::uuid()])->postJson('/api/v1/connect/device-login',['device_binding'=>$binding])->assertOk();
        auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'Bearer '.$res->json('token')]);
        return [$d->fresh()->user_id, $res->json('token')];
    }
    public function test_connect_account_can_save_map_display_preferences(): void {
        // Ook met bewerken uitgezet: voorkeuren zijn geen kaart- of accountwijziging.
        [$userId]=$this->deviceToken(['edit'=>false]);
        User::whereKey($userId)->update(['settings'=>json_encode(['show_last_seen'=>false])]);
        $this->putJson('/api/v1/users/'.$userId,['settings'=>['locationFormat'=>'mgrs']])->assertForbidden()->assertJsonPath('code','connect_policy_denied');
        $this->putJson('/api/v1/user/settings',['settings'=>['locationFormat'=>'mgrs','mgrsPrecision'=>8,'terrain3D'=>true]])->assertOk()
            ->assertJsonPath('settings.locationFormat','mgrs')->assertJsonPath('settings.terrain3D',true);
        $settings=User::find($userId)->settings;
        $this->assertSame('mgrs',$settings['locationFormat']); $this->assertTrue($settings['terrain3D']);
        $this->assertFalse($settings['show_last_seen'], 'bestaande voorkeuren blijven staan');
        $this->putJson('/api/v1/user/settings',['settings'=>['terrain3D'=>false]])->assertOk();
        $this->assertFalse(User::find($userId)->settings['terrain3D']); $this->assertSame('mgrs',User::find($userId)->settings['locationFormat']);
    }
    public function test_settings_payload_must_be_an_object_and_server_keys_stay_boolean(): void {
        $user=User::create(['first_name'=>'Test','last_name'=>'User','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
        $this->actingAs($user,'sanctum');
        $this->putJson('/api/v1/user/settings',['settings'=>['a','b']])->assertStatus(422);
        $this->putJson('/api/v1/user/settings',['settings'=>['show_last_seen'=>'false','locationFormat'=>'latlng']])->assertOk();
        $this->assertFalse($user->fresh()->settings['show_last_seen']); $this->assertSame('latlng',$user->fresh()->settings['locationFormat']);
    }
}
