<?php
namespace Tests\Feature;
use App\Models\{ConnectOrganization, ConnectMember, ConnectAllowedDevice, ConnectAuditEvent, Map, Mission, User};
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
    private function device(string $platform = 'ios'): array {
        $d=ConnectAllowedDevice::create(['organization_id'=>$this->org->id,'name'=>'Tablet','platform'=>$platform,'udid'=>strtoupper(Str::random(8)).'-123456789']);
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
    public function test_marked_areas_are_not_governed_by_the_management_policy(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['create'=>false,'edit'=>false,'delete'=>false,'share_external'=>false]]);
        $this->token($token);
        $map=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        // Een gemarkeerd gebied (report) maken, wijzigen en verwijderen heeft geen Connect-recht nodig.
        $res=$this->postJson('/api/v1/reports',['map_id'=>$map->id,'category'=>'area','type'=>'danger','latitude'=>52.1,'longitude'=>5.1])->assertSuccessful();
        $id=$res->json('id') ?? $res->json('data.id');
        $this->putJson('/api/v1/reports/'.$id,['description'=>'Changed'])->assertSuccessful();
        $this->deleteJson('/api/v1/reports/'.$id)->assertSuccessful();
        $this->assertDatabaseMissing('connect_audit_events',['organization_id'=>$this->org->id,'action'=>'DELETE api/v1/reports/{id}']);
        // Alle andere werkgegevens blijven wel onder het beleid vallen.
        $this->deleteJson('/api/v1/maps/'.$map->id)->assertForbidden()->assertJsonPath('permission','delete');
    }
    public function test_existing_public_links_obey_updated_policy(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['public_links'=>true]]); $this->token($token);
        $map=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        $res=$this->postJson('/api/v1/maps/'.$map->id.'/shares',[])->assertCreated(); $url='/api/v1/share/'.$res->json('token');
        $this->getJson($url)->assertOk(); $this->org->update(['management_policy'=>['public_links'=>false]]); $this->getJson($url)->assertForbidden();
    }
    public function test_public_share_subresources_recheck_org_policy_and_license(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['public_links'=>true]]); $this->token($token);
        $map=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        $share=$this->postJson('/api/v1/maps/'.$map->id.'/shares',[])->assertCreated()->json('token');
        auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'']);
        $paths=['/api/v1/maps/'.$map->id.'/locations?share_token='.$share,'/api/v1/maps/'.$map->id.'/routemaps?share_token='.$share];
        foreach ($paths as $path) $this->getJson($path)->assertOk();
        $this->org->update(['management_policy'=>['public_links'=>false]]);
        foreach ($paths as $path) $this->getJson($path)->assertForbidden();
        $this->org->update(['management_policy'=>['public_links'=>true], 'ends_at'=>now()->subMinute()]);
        foreach ($paths as $path) $this->getJson($path)->assertForbidden();
    }
    public function test_binding_fingerprint_is_session_bound_and_udid_mode_is_explicit(): void {
        [$android,$token]=$this->device('android');
        $this->token($token)->getJson('/api/v1/users/me')->assertOk()
            ->assertJsonPath('connect_management.device_binding_hash',$android->binding_hash)
            ->assertJsonPath('connect_management.device_login_mode','binding');
        [$ios,$token]=$this->device('ios');
        $this->token($token)->getJson('/api/v1/users/me')->assertOk()
            ->assertJsonPath('connect_management.device_binding_hash',null)
            ->assertJsonPath('connect_management.device_login_mode','udid');
        $this->org->update(['device_login_mode'=>'binding']);
        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('connect_management.device_binding_hash',$ios->binding_hash);
    }
    public function test_external_route_creator_inherits_map_ownership_and_policy(): void {
        [$d]=$this->device(); $map=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        $guest=User::create(['first_name'=>'Guest','last_name'=>'Editor','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now(),'trial_ends_at'=>now()->addDay()]);
        \Illuminate\Support\Facades\DB::table('map_collaborators')->insert(['id'=>(string)Str::uuid(),'map_id'=>$map->id,'user_id'=>$guest->id,'added_by'=>$d->user_id,'role'=>'editor','status'=>'accepted','created_at'=>now(),'updated_at'=>now()]);
        $this->org->update(['management_policy'=>['share_external'=>true,'create'=>true,'edit'=>false,'delete'=>false]]);
        $this->token($guest->createToken('test')->plainTextToken)->withHeaders(['X-Milmap-Installation'=>(string)Str::uuid()]);
        $id=$this->postJson('/api/v1/routemaps',['map_id'=>$map->id,'title'=>'External route'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('route_maps',['id'=>$id,'connect_organization_id'=>$this->org->id]);
        $this->putJson('/api/v1/routemaps/'.$id,['title'=>'Denied'])->assertForbidden()->assertJsonPath('permission','edit');
        $this->deleteJson('/api/v1/routemaps/'.$id)->assertForbidden()->assertJsonPath('permission','delete');
        // Legacy routes with missing ownership must still obey the parent policy.
        \Illuminate\Support\Facades\DB::table('route_maps')->where('id',$id)->update(['connect_organization_id'=>null]);
        $this->putJson('/api/v1/routemaps/'.$id,['title'=>'Denied legacy'])->assertForbidden()->assertJsonPath('permission','edit');
    }
    public function test_route_parent_cannot_move_work_data_out_of_its_organisation(): void {
        [$d]=$this->device(); $workMap=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        $route=\App\Models\RouteMap::create(['owner_id'=>$d->user_id,'map_id'=>$workMap->id,'title'=>'Work route']);
        $personal=User::create(['first_name'=>'Personal','last_name'=>'User','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
        auth()->forgetGuards(); $this->withHeaders(['Authorization'=>'']);
        $personalMap=Map::create(['owner_id'=>$personal->id,'title'=>'Personal','status'=>'active']);
        try { $route->update(['map_id'=>$personalMap->id]); $this->fail('Moving organisation data should fail'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('map_id',$e->errors()); }
        $this->assertDatabaseHas('route_maps',['id'=>$route->id,'map_id'=>$workMap->id,'connect_organization_id'=>$this->org->id]);
        $otherMap=Map::create(['owner_id'=>$personal->id,'title'=>'Other personal','status'=>'active']);
        $personalRoute=\App\Models\RouteMap::create(['owner_id'=>$personal->id,'map_id'=>$personalMap->id,'title'=>'Personal route']);
        $personalRoute->update(['map_id'=>$otherMap->id]);
        $this->assertSame($otherMap->id,$personalRoute->fresh()->map_id);
    }
    public function test_legacy_route_ownership_is_backfilled_from_parent_map(): void {
        [$d]=$this->device(); $map=Map::create(['owner_id'=>$d->user_id,'title'=>'Work','status'=>'active']);
        $route=\App\Models\RouteMap::create(['owner_id'=>$d->user_id,'map_id'=>$map->id,'title'=>'Legacy route']);
        \Illuminate\Support\Facades\DB::table('route_maps')->where('id',$route->id)->update(['connect_organization_id'=>null]);
        $map->delete();
        (require database_path('migrations/2026_10_17_090000_inherit_route_map_connect_ownership.php'))->up();
        $this->assertDatabaseHas('route_maps',['id'=>$route->id,'connect_organization_id'=>$this->org->id]);
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
    private function locationMap(int $userId): Map { return Map::create(['owner_id'=>$userId,'title'=>'Live','status'=>'active']); }
    private function ping(Map $map) { return $this->postJson('/api/v1/maps/'.$map->id.'/user-locations/update',['latitude'=>51.5,'longitude'=>5.3]); }
    public function test_policy_share_location_false_denies_location_updates(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['share_location'=>false]]); $this->token($token);
        $this->ping($this->locationMap($d->user_id))->assertForbidden()->assertJsonPath('code','connect_policy_denied')->assertJsonPath('permission','share_location');
        $this->assertFalse($this->getJson('/api/v1/users/me')->assertOk()->json('connect_management.permissions.share_location'));
        $this->assertDatabaseMissing('user_locations',['user_id'=>$d->user_id]);
    }
    public function test_location_sharing_does_not_require_create(): void {
        [$d,$token]=$this->device(); $this->org->update(['management_policy'=>['create'=>false]]); $this->token($token);
        $map=$this->locationMap($d->user_id);
        $this->postJson('/api/v1/maps',['title'=>'Denied'])->assertForbidden()->assertJsonPath('permission','create');
        $res=$this->ping($map); $this->assertNotSame('connect_policy_denied',$res->json('code')); $res->assertOk();
        $this->assertTrue($this->getJson('/api/v1/users/me')->json('connect_management.permissions.share_location'));
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
    public function test_location_pings_are_audited_at_most_hourly(): void {
        [$d,$token]=$this->device(); $this->token($token); $map=$this->locationMap($d->user_id);
        $pings=fn()=>ConnectAuditEvent::where('organization_id',$this->org->id)->where('action','POST api/v1/maps/{mapId}/user-locations/update')->count();
        $this->ping($map)->assertOk(); $this->travel(2)->seconds(); $this->ping($map)->assertOk(); $this->travel(2)->seconds(); $this->ping($map)->assertOk();
        $this->assertSame(1,$pings());
        $this->travel(61)->minutes(); $this->ping($map)->assertOk();
        $this->assertSame(2,$pings());
        // Stoppen is zeldzaam en blijft altijd in het audit-log staan.
        $this->deleteJson('/api/v1/maps/'.$map->id.'/user-locations/stop-sharing')->assertOk();
        $this->assertDatabaseHas('connect_audit_events',['organization_id'=>$this->org->id,'action'=>'DELETE api/v1/maps/{mapId}/user-locations/stop-sharing']);
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
    public function test_resource_org_policy_governs_external_location_sharing(): void {
        [$d]=$this->device(); $map=$this->locationMap($d->user_id); $this->assertSame($this->org->id,$map->connect_organization_id);
        $guest=User::create(['first_name'=>'Test','last_name'=>'Guest','email'=>Str::uuid().'@example.org','password'=>bcrypt('Password123!'),'email_verified_at'=>now()]);
        \Illuminate\Support\Facades\DB::table('map_collaborators')->insert(['id'=>(string)Str::uuid(),'map_id'=>$map->id,'user_id'=>$guest->id,'added_by'=>$d->user_id,'role'=>'viewer','status'=>'accepted','created_at'=>now(),'updated_at'=>now()]);
        // Eigen sessie + eigen installatie voor de gast (niet de headers van het tabletapparaat hergebruiken).
        $this->token($guest->createToken('test')->plainTextToken)->withHeaders(['X-Milmap-Installation'=>(string)Str::uuid()]);
        // Persoonlijk account (niet beheerd) op een org-kaart: de policy van de kaart-organisatie beslist.
        $this->org->update(['management_policy'=>['share_external'=>true,'share_location'=>false]]);
        $this->ping($map)->assertForbidden()->assertJsonPath('permission','share_location');
        $this->org->update(['management_policy'=>['share_external'=>true,'share_location'=>true]]);
        // Connect laat het door; daarna kan alleen nog de eigen premium-check (402) van de gast gelden.
        $res=$this->ping($map); $this->assertNotSame(403,$res->status()); $this->assertNotSame('connect_policy_denied',$res->json('code'));
        \App\Models\UserLocation::create(['map_id'=>$map->id,'user_id'=>$guest->id,'latitude'=>51.5,'longitude'=>5.3,'last_updated_at'=>now()]);
        $this->org->update(['management_policy'=>['share_external'=>true,'share_location'=>false]]);
        $this->deleteJson('/api/v1/maps/'.$map->id.'/user-locations/stop-sharing')->assertOk();
        $this->assertDatabaseMissing('user_locations',['map_id'=>$map->id,'user_id'=>$guest->id]);
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
    private function managementHeader($response): array { return json_decode(base64_decode($response->headers->get('X-Milmap-Management')),true); }
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
