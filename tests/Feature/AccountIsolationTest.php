<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\User;
use App\Policies\MapPolicy;
use App\Services\MapAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountIsolationTest extends TestCase
{
    private string $mapId;
    private string $missionId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->withoutMiddleware([
            \App\Http\Middleware\TrackLastSeen::class,
            \App\Http\Middleware\EnsureEmailVerified::class,
            \App\Http\Middleware\EnsureNotViewOnly::class,
        ]);
        Schema::create('permissions', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->string('avatar_path')->nullable();
        });
        Schema::create('maps', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('owner_id'); $t->string('title'); $t->string('status')->default('active');
            $t->json('settings')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('map_collaborators', function (Blueprint $t) {
            $t->id(); $t->uuid('map_id'); $t->integer('user_id'); $t->string('role'); $t->string('status');
        });
        Schema::create('missions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('owner_id'); $t->json('map')->nullable();
            $t->string('name')->default('Mission'); $t->string('status')->default('planning');
            $t->string('linked_team_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->id(); $t->uuid('mission_id'); $t->integer('user_id'); $t->string('role'); $t->string('status');
        });
        (require database_path('migrations/2026_09_18_140000_create_map_layer_prefs_table.php'))->up();
        (require database_path('migrations/2026_07_22_130000_create_activities_table.php'))->up();
        (require database_path('migrations/2026_08_10_100200_add_garmin_fields_to_activities_table.php'))->up();
        Schema::table('activities', fn (Blueprint $t) => $t->text('notes')->nullable());
        (require database_path('migrations/2026_09_20_110000_encrypt_activity_private_data.php'))->up();
        DB::table('users')->insert([['id' => 10], ['id' => 20], ['id' => 30]]);
        $this->mapId = (string) Str::uuid(); $this->missionId = (string) Str::uuid();
        DB::table('maps')->insert(['id' => $this->mapId, 'owner_id' => '10', 'title' => 'Private']);
        $this->login(20);
    }

    private function login(int $id): User
    {
        $user = new User(); $user->id = $id;
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function mission(int $owner, string $role = 'editor', string $source = 'server', ?string $id = null): void
    {
        $id ??= $this->missionId;
        DB::table('missions')->insert(['id' => $id, 'owner_id' => $owner, 'map' => json_encode(['id' => $this->mapId, 'source' => $source])]);
        DB::table('mission_collaborators')->insert(['mission_id' => $id, 'user_id' => 20, 'role' => $role, 'status' => 'accepted']);
    }

    public function test_existing_cross_owner_mission_does_not_grant_read_or_write(): void
    {
        $this->mission(20);
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
        $this->putJson('/api/v1/maps/'.$this->mapId, ['title' => 'Changed'])->assertForbidden();
        $this->assertSame([], app(MapAccess::class)->accessibleQuery(auth()->user())->pluck('id')->all());
        $this->assertDatabaseHas('maps', ['id' => $this->mapId, 'title' => 'Private']);
    }

    public function test_foreign_link_rejected_on_create_and_update(): void
    {
        $link = ['id' => $this->mapId, 'source' => 'server'];
        $this->postJson('/api/v1/missions', ['name' => 'Bad link', 'map' => $link])->assertUnprocessable()->assertJsonValidationErrors('map');
        DB::table('missions')->insert(['id' => $this->missionId, 'owner_id' => 20]);
        $this->putJson('/api/v1/missions/'.$this->missionId, ['map' => $link])->assertUnprocessable();
        $this->assertDatabaseHas('missions', ['id' => $this->missionId, 'map' => null]);
    }

    public function test_editor_cannot_replace_mission_map_even_with_own_map(): void
    {
        $this->mission(10);
        $own = (string) Str::uuid();
        DB::table('maps')->insert(['id' => $own, 'owner_id' => '20', 'title' => 'Own']);
        $this->putJson('/api/v1/missions/'.$this->missionId, ['map' => ['id' => $own, 'source' => 'server']])->assertForbidden();
        $this->putJson('/api/v1/missions/'.$this->missionId, ['map' => null])->assertForbidden();
    }

    public function test_valid_owner_link_preserves_viewer_editor_and_revocation_rules(): void
    {
        $this->mission(10, 'viewer');
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertOk()->assertJsonPath('can_edit', false);
        $this->putJson('/api/v1/maps/'.$this->mapId, ['title' => 'No'])->assertForbidden();
        DB::table('mission_collaborators')->update(['role' => 'editor']);
        $this->putJson('/api/v1/maps/'.$this->mapId, ['title' => 'Allowed'])->assertOk();
        DB::table('mission_collaborators')->update(['status' => 'pending']);
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
    }

    public function test_local_map_reference_cannot_grant_server_map_access(): void
    {
        $this->mission(10, 'editor', 'local');
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
        $this->assertSame([], app(MapAccess::class)->accessibleQuery(auth()->user())->pluck('id')->all());
    }

    public function test_multiple_missions_combine_roles_without_first_row_winning(): void
    {
        $this->mission(10, 'viewer');
        $this->mission(10, 'editor', 'server', (string) Str::uuid());
        $this->assertSame('editor', (new MapPolicy())->roleFor(auth()->user(), Map::findOrFail($this->mapId)));
    }

    public function test_unknown_and_pending_direct_roles_fail_closed(): void
    {
        DB::table('map_collaborators')->insert(['map_id' => $this->mapId, 'user_id' => 20, 'role' => 'unknown', 'status' => 'accepted']);
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
        DB::table('map_collaborators')->update(['role' => 'editor', 'status' => 'pending']);
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
        DB::table('map_collaborators')->update(['status' => 'accepted']);
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertOk();
        $this->login(30);
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
    }

    public function test_preferences_use_one_authorization_query_and_never_write_foreign_maps(): void
    {
        $own = (string) Str::uuid();
        DB::table('maps')->insert(['id' => $own, 'owner_id' => '20', 'title' => 'Own']);
        $this->mission(20);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->putJson('/api/v1/workspace/layer-prefs', ['layers' => [['map_id' => $own], ['map_id' => $this->mapId]]])
            ->assertOk()->assertJsonPath('saved', 1);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'select'));
        $this->assertCount(1, $queries);
        $this->assertDatabaseMissing('map_layer_prefs', ['map_id' => $this->mapId]);
        $this->assertDatabaseHas('map_layer_prefs', ['map_id' => $own, 'user_id' => 20]);
        DB::disableQueryLog();
    }

    public function test_batch_query_matches_policy_for_valid_mission_and_direct_grants(): void
    {
        $this->mission(10);
        $this->assertSame([$this->mapId], app(MapAccess::class)->accessibleQuery(auth()->user())->pluck('id')->all());
        DB::table('missions')->update(['deleted_at' => now()]);
        $this->assertSame([], app(MapAccess::class)->accessibleQuery(auth()->user())->pluck('id')->all());
        $this->getJson('/api/v1/maps/'.$this->mapId.'/0,0')->assertForbidden();
    }

    public function test_accounts_and_anonymous_ips_have_separate_rate_limit_buckets(): void
    {
        $limiter = RateLimiter::limiter('api');
        $keys = [];
        foreach ([10, 20] as $id) {
            $request = Request::create('/'); $user = new User(); $user->id = $id;
            $request->setUserResolver(fn () => $user);
            $keys[] = $limiter($request)->key;
        }
        foreach (['192.0.2.1', '192.0.2.2'] as $ip) {
            $keys[] = $limiter(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]))->key;
        }
        $this->assertCount(4, array_unique($keys));
        foreach ($keys as $key) $this->assertNotSame('', $key);
    }

    public function test_activity_list_excludes_track_at_sql_boundary_and_remains_account_scoped(): void
    {
        foreach ([10, 20] as $id) \App\Models\Activity::create([
            'user_id' => $id, 'title' => 'Track '.$id, 'started_at' => now(),
            'points' => json_encode([['lat' => 52.1, 'lon' => 5.2], ['lat' => 52.2, 'lon' => 5.3]]),
        ]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->getJson('/api/v1/activities')->assertOk()->assertJsonCount(1, 'activities')
            ->assertJsonPath('activities.0.title', 'Track 20')->assertJsonPath('activities.0.start_lat', 52.1)
            ->assertJsonMissingPath('activities.0.points');
        $sql = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'from "activities"'))['query'];
        $this->assertStringNotContainsString('select *', $sql);
        $this->assertStringNotContainsString('"points"', $sql);
        $this->assertStringNotContainsString('JSON_EXTRACT', $sql);
        $this->assertDatabaseMissing('activities', ['user_id' => 20, 'points_ciphertext' => null]);
        $this->assertNull(DB::table('activities')->where('user_id', 20)->value('points'));
        DB::disableQueryLog();
    }

    public function test_realtime_channels_use_same_policy_and_string_owner_id_works(): void
    {
        $channels = \Illuminate\Support\Facades\Broadcast::getChannels();
        foreach (['map.{mapId}', 'map.{mapId}.locations'] as $name) {
            $callback = $channels[$name];
            $this->assertTrue($callback($this->login(10), $this->mapId));
            $this->assertFalse($callback($this->login(20), $this->mapId));
            $this->mission(10, 'viewer', 'server', (string) Str::uuid());
            $this->assertTrue($callback(auth()->user(), $this->mapId));
            DB::table('mission_collaborators')->delete();
        }
    }
    public function test_common_owner_can_link_map_without_breaking_mission_editing(): void
    {
        DB::table('missions')->insert(['id' => $this->missionId, 'owner_id' => 10]);
        $this->login(10);
        $this->putJson('/api/v1/missions/'.$this->missionId, [
            'map' => ['id' => $this->mapId, 'source' => 'server'],
        ])->assertOk()->assertJsonPath('data.map.id', $this->mapId);
    }

    public function test_rate_limit_exhaustion_does_not_block_another_account(): void
    {
        $this->getJson('/api/v1/activities')->assertOk();
        $key = md5('apiuser:20');
        for ($i = 0; $i < 600; $i++) RateLimiter::hit($key, 60);
        $this->getJson('/api/v1/activities')->assertTooManyRequests();
        $this->login(10);
        $this->getJson('/api/v1/activities')->assertOk();
    }

    public function test_gps_events_are_never_broadcast_on_public_channels(): void
    {
        $location = new \App\Models\UserLocation(); $location->map_id = $this->mapId;
        foreach ([new \App\Events\UserLocationUpdated($location), new \App\Events\UserLocationRemoved($this->mapId, 10, 'Owner')] as $event) {
            foreach ($event->broadcastOn() as $channel) {
                $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $channel);
                $this->assertSame('private-map.'.$this->mapId.'.locations', $channel->name);
            }
        }
    }

}
