<?php
namespace Tests\Feature;
use App\Models\{ConnectOrganization, ConnectMember, ConnectAllowedDevice, ConnectAuditEvent, Map, Mission, User};
use App\Services\{ConnectDeviceLogin, ConnectManagement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
// Live locatie delen (share_location): beleid in Connect -> app-context -> serverhandhaving.
class ConnectLocationPolicyTest extends TestCase {
    use RefreshDatabase;
    private ConnectOrganization $org;
    private User $admin;
    protected function setUp(): void {
        parent::setUp(); Mail::fake();
        $this->org=ConnectOrganization::create(['name'=>'Organisation','status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addDays(10),'seat_limit'=>10]);
        $this->admin=User::create(['first_name'=>'Test','last_name'=>'User','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
        ConnectMember::create(['organization_id'=>$this->org->id,'user_id'=>$this->admin->id,'email'=>$this->admin->email,'first_name'=>'Admin','last_name'=>'Owner','role'=>'owner','status'=>'active']);
    }
    private function device(string $platform = 'ios'): array {
        $d=ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'Tablet','platform'=>$platform,'udid'=>strtoupper(Str::random(8)).'-123456789']);
        $binding=app(ConnectDeviceLogin::class)->provision($d); auth()->forgetGuards();
        $res=$this->withHeaders(['Authorization'=>'','X-Milmap-Installation'=>(string)Str::uuid()])->postJson('/api/v1/connect/device-login',['device_binding'=>$binding])->assertOk();
        return [$d->fresh(),$res->json('token')];
    }
    private function token(string $token): self { auth()->forgetGuards(); return $this->withHeaders(['Authorization'=>'Bearer '.$token]); }
    private function manager(): self { auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'']); Sanctum::actingAs($this->admin); return $this; }
    private function locationMap(int $userId): Map { return Map::create(['owner_id'=>$userId,'title'=>'Live','status'=>'active']); }
    private function ping(Map $map) { return $this->postJson('/api/v1/maps/'.$map->id.'/user-locations/update',['latitude'=>51.5,'longitude'=>5.3]); }
    private function managementHeader($response): array { return json_decode(base64_decode($response->headers->get('X-Milmap-Management')),true); }
    public function test_policy_share_location_false_denies_location_updates(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['share_location'=>false]]); $this->token($token);
        $this->ping($this->locationMap($d->user_id))->assertForbidden()->assertJsonPath('code','connect_policy_denied')->assertJsonPath('permission','share_location');
        $this->assertFalse($this->getJson('/api/v1/users/me')->assertOk()->json('connect_management.permissions.share_location'));
        $this->assertDatabaseMissing('user_locations',['user_id'=>$d->user_id]);
    }
    public function test_stop_sharing_needs_no_delete_or_share_location_permission(): void {
        [$d,$token]=$this->device(); $this->token($token); $map=$this->locationMap($d->user_id);
        $this->ping($map)->assertOk();
        // delete staat standaard uit en ook share_location intrekken mag stoppen nooit blokkeren.
        $this->org->update(['management_policy'=>['delete'=>false,'share_location'=>false]]);
        $this->deleteJson('/api/v1/maps/'.$map->id.'/user-locations/stop-sharing')->assertOk()->assertJsonPath('success',true);
        $this->assertDatabaseMissing('user_locations',['map_id'=>$map->id,'user_id'=>$d->user_id]);
        // Andere deletes op dezelfde kaart blijven wel onder 'delete' vallen.
        $this->deleteJson('/api/v1/maps/'.$map->id)->assertForbidden()->assertJsonPath('permission','delete');
    }
    public function test_member_permission_narrows_share_location(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['share_location'=>true]]);
        ConnectMember::where('user_id',$d->user_id)->first()->update(['permissions'=>['share_location'=>false]]);
        $this->token($token)->ping($this->locationMap($d->user_id))->assertForbidden()->assertJsonPath('permission','share_location');
        // Een lid-recht kan een uitgezette organisatie-policy nooit weer aanzetten.
        $this->org->update(['management_policy'=>['share_location'=>false]]);
        ConnectMember::where('user_id',$d->user_id)->first()->update(['permissions'=>['share_location'=>true]]);
        $this->ping($this->locationMap($d->user_id))->assertForbidden()->assertJsonPath('permission','share_location');
    }
    public function test_mission_tracks_require_share_location_not_create(): void {
        [$d,$token]=$this->device(); $this->token($token);
        $mission=Mission::create(['owner_id'=>$d->user_id,'name'=>'Oefening']);
        $url='/api/v1/missions/'.$mission->id.'/tracks'; $points=['points'=>[['lat'=>51.5,'lng'=>5.3,'recorded_at'=>now()->toIso8601String()]]];
        $this->org->update(['management_policy'=>['share_location'=>false]]);
        $this->postJson($url,$points)->assertForbidden()->assertJsonPath('code','connect_policy_denied')->assertJsonPath('permission','share_location');
        $this->org->update(['management_policy'=>['create'=>false,'share_location'=>true]]);
        $this->postJson($url,$points)->assertCreated()->assertJsonPath('stored',1);
    }
    public function test_policy_update_from_older_portal_keeps_share_location(): void {
        $base='/api/v1/connect/organizations/'.$this->org->id; $policy=ConnectManagement::DEFAULTS;
        $this->manager()->putJson($base.'/policy',array_replace($policy,['share_location'=>false]))->assertOk()->assertJsonPath('policy.share_location',false);
        // Oudere portaalbuild kent share_location niet: de opgeslagen waarde blijft staan (geen stille fail-open).
        unset($policy['share_location']);
        $this->putJson($base.'/policy',$policy)->assertOk()->assertJsonPath('policy.share_location',false);
        $this->putJson($base.'/policy',$policy+['share_location'=>true])->assertOk()->assertJsonPath('policy.share_location',true);
    }
    public function test_percent_encoded_paths_cannot_bypass_share_location(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['share_location'=>false]]); $this->token($token);
        $map=$this->locationMap($d->user_id); $mission=Mission::create(['owner_id'=>$d->user_id,'name'=>'Oefening']);
        // De router decodeert het pad vóór het matchen; de middleware moet hetzelfde pad zien.
        $this->postJson('/api/v1/maps/'.$map->id.'/user-locations/upd%61te',['latitude'=>51.5,'longitude'=>5.3])->assertForbidden()->assertJsonPath('permission','share_location');
        $this->postJson('/api/v1/missions/'.$mission->id.'/track%73',['points'=>[['lat'=>51.5,'lng'=>5.3,'recorded_at'=>now()->toIso8601String()]]])->assertForbidden()->assertJsonPath('permission','share_location');
        $this->assertDatabaseMissing('user_locations',['user_id'=>$d->user_id]);
        // Ook een gecodeerd teken in het kaart-ID mag de resource-organisatiecheck niet omzeilen.
        $guest=User::create(['first_name'=>'Test','last_name'=>'Guest','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
        \Illuminate\Support\Facades\DB::table('map_collaborators')->insert(['id'=>(string)Str::uuid(),'map_id'=>$map->id,'user_id'=>$guest->id,'added_by'=>$d->user_id,'role'=>'viewer','status'=>'accepted','created_at'=>now(),'updated_at'=>now()]);
        $this->token($guest->createToken('test')->plainTextToken)->withHeaders(['X-Milmap-Installation'=>(string)Str::uuid()]);
        $this->org->update(['management_policy'=>['share_external'=>true,'share_location'=>false]]);
        $encodedId=sprintf('%%%02X',ord($map->id[0])).substr($map->id,1);
        $this->postJson('/api/v1/maps/'.$encodedId.'/user-locations/upd%61te',['latitude'=>51.5,'longitude'=>5.3])->assertForbidden()->assertJsonPath('permission','share_location');
        // Stoppen blijft ook via een gecodeerd pad altijd mogelijk.
        \App\Models\UserLocation::create(['map_id'=>$map->id,'user_id'=>$guest->id,'latitude'=>51.5,'longitude'=>5.3,'last_updated_at'=>now()]);
        $this->deleteJson('/api/v1/maps/'.$map->id.'/user-locations/stop-sh%61ring')->assertOk();
        $this->assertDatabaseMissing('user_locations',['map_id'=>$map->id,'user_id'=>$guest->id]);
    }
    public function test_portal_share_location_toggle_reaches_app_context(): void {
        // Volledige keten: beheerder zet het recht in het Connect-portaal; de app leest het uit /users/me
        // en uit de X-Milmap-Management-header van elke API-respons (zonder opnieuw in te loggen).
        [$d,$token]=$this->device(); $base='/api/v1/connect/organizations/'.$this->org->id; $map=$this->locationMap($d->user_id);
        $this->manager()->getJson($base.'/policy')->assertOk()->assertJsonPath('policy.share_location',true);
        $this->putJson($base.'/policy',array_replace(ConnectManagement::DEFAULTS,['share_location'=>false]))->assertOk()->assertJsonPath('policy.share_location',false);
        $this->token($token)->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('connect_management.permissions.share_location',false);
        $this->ping($map)->assertForbidden()->assertJsonPath('permission','share_location');
        $this->manager()->putJson($base.'/policy',array_replace(ConnectManagement::DEFAULTS,['share_location'=>true]))->assertOk()->assertJsonPath('policy.share_location',true);
        $this->assertTrue($this->org->fresh()->management_policy['share_location']);
        $res=$this->token($token)->ping($map)->assertOk();
        $this->assertTrue($this->managementHeader($res)['permissions']['share_location']);
        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('connect_management.permissions.share_location',true);
    }
    public function test_member_share_location_override_can_be_reset_to_policy(): void {
        [$d,$token]=$this->device(); $base='/api/v1/connect/organizations/'.$this->org->id; $map=$this->locationMap($d->user_id);
        $member=ConnectMember::where('user_id',$d->user_id)->firstOrFail();
        $this->manager()->putJson($base.'/members/'.$member->id.'/permissions',['permissions'=>['share_location'=>false]])->assertOk()->assertJsonPath('member.permissions.share_location',false);
        $this->token($token)->ping($map)->assertForbidden()->assertJsonPath('permission','share_location');
        // Terug naar "Volgt beleid" (het portaal stuurt dan een leeg object) moet kunnen; anders blijft het lid geblokkeerd.
        $this->manager()->putJson($base.'/members/'.$member->id.'/permissions',['permissions'=>(object)[]])->assertOk();
        $this->assertSame([],$member->fresh()->permissions);
        $this->token($token)->ping($map)->assertOk();
        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('connect_management.permissions.share_location',true);
        // Onbekende sleutels blijven geweigerd.
        $this->manager()->putJson($base.'/members/'.$member->id.'/permissions',['permissions'=>['admin'=>true]])->assertUnprocessable();
    }
}
