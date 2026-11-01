<?php

namespace Tests\Feature;

use App\Models\PublicProfile;
use App\Models\RouteMap;
use App\Models\User;
use App\Support\RouteGeometry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RouteMapDiscoveryColumns;
use Tests\TestCase;

/**
 * Een routekaart bewaart het gesnapte pad, de sport en het hoogteprofiel, en is
 * daarmee vindbaar (bbox) en te beoordelen. Het pad hoort bij de waypoints
 * waarmee het is opgeslagen: veranderen die, dan vervalt het pad.
 */
class RouteMapDiscoveryFieldsTest extends TestCase
{
    private const OWNER = 10;
    private const OTHER = 20;

    private string $mapId;

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
            $t->id();
            $t->string('email')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('avatar_path')->nullable();
            $t->string('language')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->string('owner_id');
            $t->string('title')->nullable();
            $t->json('settings')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('map_collaborators', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('map_id'); $t->integer('user_id'); $t->integer('added_by')->nullable();
            $t->string('role'); $t->string('status'); $t->string('invitation_token')->nullable();
            $t->timestamp('invited_at')->nullable(); $t->timestamp('accepted_at')->nullable(); $t->timestamps();
        });
        Schema::create('missions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->integer('owner_id');
            $t->string('name')->nullable();
            $t->string('map_ref_id')->nullable();
            $t->string('status')->default('planning');
            $t->uuid('linked_team_id')->nullable();
            $t->json('map')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->id(); $t->uuid('team_id'); $t->integer('user_id'); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('mission_id'); $t->integer('user_id');
            $t->string('role')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('route_maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->uuid('map_id');
            $t->uuid('mission_id')->nullable();
            $t->string('owner_id');
            $t->string('title')->nullable();
            $t->string('color')->nullable();
            $t->float('declination')->nullable();
            $t->float('speed')->nullable();
            $t->json('locations')->nullable();
            $t->json('meta')->nullable();
            $t->integer('pause_time')->default(0);
            $t->integer('total_time')->default(0);
            $t->float('total_distance')->default(0);
            $t->float('total_elevation')->default(0);
            $t->boolean('is_public')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->string('cloned_from_type', 16)->nullable();
            $t->string('cloned_from_id', 36)->nullable();
            $t->unsignedBigInteger('cloned_from_user_id')->nullable();
            RouteMapDiscoveryColumns::add($t);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('map_id')->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->string('title')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->unsignedInteger('distance_m')->default(0);
            $t->integer('elevation_gain_m')->default(0);
            $t->unsignedInteger('moving_time_s')->default(0);
            $t->timestamps();
        });
        Schema::create('public_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->string('handle', 30)->unique();
            $t->string('display_name', 60)->nullable();
            $t->string('bio', 300)->nullable();
            $t->boolean('enabled')->default(false);
            $t->boolean('hide_endpoints')->default(true);
            $t->timestamps();
        });

        foreach ([self::OWNER => 'eigenaar@example.test', self::OTHER => 'ander@example.test'] as $id => $email) {
            DB::table('users')->insert(['id' => $id, 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->mapId = (string) Str::uuid();
        DB::table('maps')->insert([
            'id' => $this->mapId, 'owner_id' => (string) self::OWNER, 'title' => 'Alpen',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function loginAs(int $id): void
    {
        $user = new User();
        $user->id = $id;
        $user->trial_ends_at = now()->addDay();
        $this->actingAs($user, 'sanctum');
    }

    private function wp(float $lat, float $lon, string $title = ''): array
    {
        return ['no' => 1, 'title' => $title, 'lat' => $lat, 'lon' => $lon, 'mgrs' => '32TLR0000'];
    }

    /** Een gesnapt pad van 3 waypoints met flink wat tussenliggende punten ([lat, lon]). */
    private function path(): array
    {
        $path = [];
        for ($i = 0; $i <= 100; $i++) {
            $path[] = [45.80 + $i * 0.0004, 6.80 + sin($i / 8) * 0.003 + $i * 0.0003];
        }

        return $path;
    }

    /** @return array<int, array{0: int, 1: int}> */
    private function profile(): array
    {
        return [[0, 1000], [500, 1100], [1000, 1250], [1500, 1200], [2000, 1400]];
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'map_id' => $this->mapId,
            'title' => 'Naar de top',
            'locations' => [$this->wp(45.80, 6.80, 'Start'), $this->wp(45.84, 6.83, 'Top')],
            'path' => $this->path(),
            'sport' => 'hike',
            'elevation_profile' => $this->profile(),
        ];
    }

    private function store(array $extra = []): string
    {
        $this->loginAs(self::OWNER);

        return $this->postJson('/api/v1/routemaps', $this->payload($extra))->assertCreated()->json('data.id');
    }

    // ── opslaan ──────────────────────────────────────────────────────────

    public function test_storing_a_route_keeps_the_snapped_path_sport_profile_and_climb(): void
    {
        $id = $this->store();
        $route = RouteMap::findOrFail($id);

        $this->assertSame('hike', $route->sport);
        $this->assertNotEmpty($route->path_polyline);
        $decoded = RouteGeometry::decode($route->path_polyline);
        $this->assertGreaterThan(10, count($decoded));
        $this->assertLessThanOrEqual(RouteGeometry::MAX_PATH_POINTS, count($decoded));
        $this->assertEqualsWithDelta(45.80, $decoded[0][0], 0.00002);

        // Stijgen/dalen uit het profiel (1000→1250 = +250, 1250→1200 = −50 telt mee, +200).
        $this->assertSame(450, $route->ascent_m);
        $this->assertSame(50, $route->descent_m);
        $this->assertSame(450.0, (float) $route->total_elevation, 'total_elevation volgt het echte stijgen als het leeg was');
        $this->assertSame('milmap', $route->source);
        $this->assertNull($route->attribution);
    }

    public function test_bbox_follows_the_path_not_just_the_waypoints(): void
    {
        $route = RouteMap::findOrFail($this->store());

        $pathBounds = RouteGeometry::bounds($this->path());
        $this->assertEqualsWithDelta($pathBounds[0], $route->bbox_min_lat, 0.00002);
        $this->assertEqualsWithDelta($pathBounds[2], $route->bbox_max_lat, 0.00002);
        $this->assertEqualsWithDelta($pathBounds[1], $route->bbox_min_lon, 0.00002);
        $this->assertEqualsWithDelta($pathBounds[3], $route->bbox_max_lon, 0.00002);
    }

    public function test_a_route_without_a_path_still_gets_a_bbox_from_its_waypoints(): void
    {
        $id = $this->store(['path' => null, 'elevation_profile' => null, 'sport' => null]);
        $route = RouteMap::findOrFail($id);

        $this->assertNull($route->path_polyline);
        $this->assertSame([45.80, 6.80, 45.84, 6.83], [$route->bbox_min_lat, $route->bbox_min_lon, $route->bbox_max_lat, $route->bbox_max_lon]);
    }

    public function test_the_client_cannot_set_source_or_attribution(): void
    {
        $id = $this->store(['source' => 'osm', 'attribution' => '© iemand', 'source_ref' => 'relation/1', 'difficulty' => 'T6']);
        $route = RouteMap::findOrFail($id);

        $this->assertSame('milmap', $route->source);
        $this->assertNull($route->attribution);
        $this->assertNull($route->source_ref);
        $this->assertNull($route->difficulty);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->loginAs(self::OWNER);

        $this->postJson('/api/v1/routemaps', $this->payload(['sport' => 'zweefvliegen']))->assertUnprocessable()->assertJsonValidationErrors('sport');
        $this->postJson('/api/v1/routemaps', $this->payload(['path' => [[95.0, 6.0], [45.0, 6.1]]]))->assertUnprocessable();
        $this->postJson('/api/v1/routemaps', $this->payload(['path' => array_fill(0, 4001, [45.0, 6.0])]))->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->postJson('/api/v1/routemaps', $this->payload(['elevation_profile' => [[0, 100000]]]))->assertUnprocessable();
        $this->postJson('/api/v1/routemaps', $this->payload(['ascent_m' => -5]))->assertUnprocessable()->assertJsonValidationErrors('ascent_m');
    }

    public function test_client_supplied_ascent_wins_over_the_computed_one(): void
    {
        $route = RouteMap::findOrFail($this->store(['ascent_m' => 321, 'descent_m' => 12]));

        $this->assertSame(321, $route->ascent_m);
        $this->assertSame(12, $route->descent_m);
    }

    // ── bijwerken ────────────────────────────────────────────────────────

    public function test_updating_other_fields_keeps_the_path(): void
    {
        $id = $this->store();
        $before = RouteMap::findOrFail($id)->path_polyline;

        $this->putJson("/api/v1/routemaps/$id", ['title' => 'Nieuwe naam'])->assertOk();
        // Dezelfde waypoints opnieuw meesturen mag het pad niet wissen.
        $this->putJson("/api/v1/routemaps/$id", ['locations' => $this->payload()['locations']])->assertOk();

        $route = RouteMap::findOrFail($id);
        $this->assertSame($before, $route->path_polyline);
        $this->assertSame(450, $route->ascent_m);
    }

    public function test_moving_a_waypoint_without_a_new_path_drops_the_stale_path(): void
    {
        $id = $this->store();

        $this->putJson("/api/v1/routemaps/$id", [
            'locations' => [$this->wp(45.80, 6.80, 'Start'), $this->wp(46.10, 7.10, 'Andere top')],
        ])->assertOk();

        $route = RouteMap::findOrFail($id);
        $this->assertNull($route->path_polyline);
        $this->assertNull($route->elevation_profile);
        $this->assertNull($route->ascent_m);
        $this->assertNull($route->descent_m);
        $this->assertSame('hike', $route->sport, 'de sport blijft');
        // De bbox volgt nu de waypoints.
        $this->assertSame([45.80, 6.80, 46.10, 7.10], [$route->bbox_min_lat, $route->bbox_min_lon, $route->bbox_max_lat, $route->bbox_max_lon]);
    }

    public function test_moving_a_waypoint_together_with_a_new_path_replaces_it(): void
    {
        $id = $this->store();
        $newPath = [[46.0, 7.0], [46.05, 7.05], [46.1, 7.1]];

        $this->putJson("/api/v1/routemaps/$id", [
            'locations' => [$this->wp(46.0, 7.0), $this->wp(46.1, 7.1)],
            'path' => $newPath,
        ])->assertOk();

        $route = RouteMap::findOrFail($id);
        $this->assertCount(3, RouteGeometry::decode($route->path_polyline));
        $this->assertEqualsWithDelta(46.0, $route->bbox_min_lat, 0.00002);

        // En het nieuwe pad hoort nu bij de nieuwe waypoints: opnieuw sturen wist het niet.
        $this->putJson("/api/v1/routemaps/$id", ['locations' => [$this->wp(46.0, 7.0), $this->wp(46.1, 7.1)]])->assertOk();
        $this->assertNotNull(RouteMap::findOrFail($id)->path_polyline);
    }

    public function test_an_empty_path_clears_the_stored_path_and_profile(): void
    {
        $id = $this->store();

        $this->putJson("/api/v1/routemaps/$id", ['path' => []])->assertOk();

        $route = RouteMap::findOrFail($id);
        $this->assertNull($route->path_polyline);
        $this->assertNull($route->elevation_profile);
        $this->assertNull($route->ascent_m);
    }

    // ── openbaar tonen en klonen ─────────────────────────────────────────

    private function publish(string $routeId): void
    {
        PublicProfile::create(['user_id' => self::OWNER, 'handle' => 'bergbeklimmer', 'enabled' => true]);
        $this->putJson("/api/v1/routemaps/$routeId/visibility", ['is_public' => true])->assertOk();
    }

    public function test_the_public_detail_exposes_path_profile_and_discovery_facts_but_no_internals(): void
    {
        $id = $this->store();
        $this->publish($id);

        $this->loginAs(self::OTHER);
        $res = $this->getJson("/api/v1/profiles/bergbeklimmer/routes/$id")->assertOk();

        $route = $res->json('route');
        $this->assertSame(RouteMap::find($id)->path_polyline, $route['path']);
        $this->assertSame('hike', $route['sport']);
        $this->assertSame(450, $route['ascent_m']);
        $this->assertSame('milmap', $route['source']);
        $this->assertTrue($route['has_path']);
        $this->assertCount(4, $route['bbox']);
        $this->assertSame(1000, $route['elevation_profile'][0][1]);
        $this->assertArrayNotHasKey('path_hash', $route);
        $this->assertArrayNotHasKey('owner_id', $route);
    }

    public function test_the_public_summary_preview_follows_the_snapped_path(): void
    {
        $id = $this->store();
        $this->publish($id);

        $this->loginAs(self::OTHER);
        $route = $this->getJson('/api/v1/profiles/bergbeklimmer')->assertOk()->json('routes.0');

        $this->assertSame('hike', $route['sport']);
        $this->assertSame(450, $route['ascent_m']);
        $this->assertCount(40, $route['preview'], 'het pad is dichter dan de 40 voorbeeldpunten');
        // Met alleen de twee waypoints zou de preview maar 2 punten hebben.
    }

    public function test_cloning_carries_the_path_sport_profile_and_source_over(): void
    {
        $id = $this->store();
        RouteMap::whereKey($id)->update(['source' => 'osm', 'source_ref' => 'relation/123', 'attribution' => '© OpenStreetMap-bijdragers']);
        $this->publish($id);

        $this->loginAs(self::OTHER);
        $res = $this->postJson("/api/v1/profiles/bergbeklimmer/routes/$id/clone")->assertCreated();

        $copy = RouteMap::findOrFail($res->json('routemap.id'));
        $this->assertSame(RouteMap::find($id)->path_polyline, $copy->path_polyline);
        $this->assertSame('hike', $copy->sport);
        $this->assertSame(450, $copy->ascent_m);
        $this->assertSame(RouteMap::find($id)->elevation_profile, $copy->elevation_profile);
        $this->assertSame('osm', $copy->source);
        $this->assertSame('relation/123', $copy->source_ref);
        $this->assertSame('© OpenStreetMap-bijdragers', $copy->attribution);
        $this->assertNotNull($copy->bbox_min_lat);

        // Het pad hoort bij de waypoints van de kopie: een gewone update wist het niet.
        $this->putJson("/api/v1/routemaps/{$copy->id}", ['title' => 'Mijn variant'])->assertOk();
        $this->assertNotNull(RouteMap::findOrFail($copy->id)->path_polyline);
    }

    // ── bestaande routes aanvullen ───────────────────────────────────────

    public function test_backfill_adds_a_bbox_to_existing_routes_only(): void
    {
        $locations = json_encode([$this->wp(51.0, 5.0), $this->wp(51.5, 5.5)]);
        $oud = (string) Str::uuid();
        $heeftAl = (string) Str::uuid();
        $leeg = (string) Str::uuid();
        $base = ['map_id' => $this->mapId, 'owner_id' => (string) self::OWNER, 'created_at' => now(), 'updated_at' => now()];

        DB::table('route_maps')->insert($base + ['id' => $oud, 'locations' => $locations]);
        DB::table('route_maps')->insert($base + ['id' => $heeftAl, 'locations' => $locations, 'bbox_min_lat' => 1, 'bbox_min_lon' => 1, 'bbox_max_lat' => 2, 'bbox_max_lon' => 2]);
        DB::table('route_maps')->insert($base + ['id' => $leeg, 'locations' => json_encode([])]);

        $this->assertSame(1, RouteGeometry::backfillBounds());

        $this->assertEquals(
            [51.0, 5.0, 51.5, 5.5],
            array_values((array) DB::table('route_maps')->where('id', $oud)->first(['bbox_min_lat', 'bbox_min_lon', 'bbox_max_lat', 'bbox_max_lon']))
        );
        $this->assertSame(1.0, (float) DB::table('route_maps')->where('id', $heeftAl)->value('bbox_min_lat'), 'bestaande bbox blijft');
        $this->assertNull(DB::table('route_maps')->where('id', $leeg)->value('bbox_min_lat'));
        $this->assertSame(0, RouteGeometry::backfillBounds(), 'herhaalbaar');
    }
}
