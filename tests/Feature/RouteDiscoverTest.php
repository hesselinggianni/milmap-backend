<?php

namespace Tests\Feature;

use App\Models\PublicProfile;
use App\Models\RouteMap;
use App\Models\TrailRoute;
use App\Models\User;
use App\Support\RouteGeometry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RouteMapDiscoveryColumns;
use Tests\TestCase;

/**
 * Routes van anderen ontdekken: alleen wat de maker zelf publiceerde, alleen via
 * een openbaar profiel, en nooit meer dan de presenter-whitelist toont.
 */
class RouteDiscoverTest extends TestCase
{
    private const OWNER = 10;
    private const OTHER = 20;
    private const THIRD = 30;

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
        // Geïmporteerde OpenStreetMap-routes: de echte migratie, zodat de test het schema niet nabootst.
        (require base_path('database/migrations/2026_10_30_000000_create_trail_routes_table.php'))->up();
        (require base_path('database/migrations/2026_11_01_120000_add_preview_to_trail_routes_table.php'))->up();

        foreach ([self::OWNER => 'eigenaar@example.test', self::OTHER => 'ander@example.test', self::THIRD => 'derde@example.test'] as $id => $email) {
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

    private function profile(int $userId, string $handle, bool $enabled = true): void
    {
        PublicProfile::create(['user_id' => $userId, 'handle' => $handle, 'display_name' => 'Anna Bergen', 'enabled' => $enabled]);
    }

    /** Een route rond (lat, lon): 41 punten oostwaarts, gepubliceerd door $owner. */
    private function route(int $owner, float $lat, float $lon, array $extra = []): RouteMap
    {
        $path = [];
        for ($i = 0; $i <= 40; $i++) {
            $path[] = [$lat + $i * 0.0002, $lon + $i * 0.0005];
        }

        $route = RouteMap::create($extra + [
            'map_id' => $this->mapId,
            'owner_id' => (string) $owner,
            'title' => 'Naar de top',
            'color' => '#3b82f6',
            'locations' => [
                ['no' => 1, 'lat' => $lat, 'lon' => $lon, 'title' => 'Start', 'notes' => 'geheim'],
                ['no' => 2, 'lat' => $lat + 0.008, 'lon' => $lon + 0.02, 'title' => 'Top'],
            ],
            'total_distance' => 4200,
            'total_elevation' => 380,
            'path_polyline' => RouteGeometry::encode($path),
            'elevation_profile' => [[0, 1000], [2000, 1300], [4200, 1380]],
            'ascent_m' => 380,
            'sport' => 'hike',
            'is_public' => true,
            'published_at' => now(),
        ]);

        return $route;
    }

    private function discover(array $query)
    {
        return $this->getJson('/api/v1/routes/discover?' . http_build_query($query));
    }

    private const AREA = ['bbox' => '6.0,45.0,7.5,46.0']; // minLon,minLat,maxLon,maxLat

    // ── vinden ───────────────────────────────────────────────────────────

    public function test_it_lists_a_route_another_user_published_with_what_the_presenter_allows(): void
    {
        $this->profile(self::OWNER, 'anna');
        $route = $this->route(self::OWNER, 45.8, 6.8);

        $this->loginAs(self::OTHER);
        $res = $this->discover(self::AREA)->assertOk();

        $this->assertCount(1, $res->json('routes'));
        $item = $res->json('routes.0');
        $this->assertSame($route->id, $item['id']);
        $this->assertSame('Naar de top', $item['title']);
        $this->assertSame('hike', $item['sport']);
        $this->assertSame(380, $item['ascent_m']);
        $this->assertTrue($item['has_path']);
        $this->assertCount(40, $item['preview']);
        $this->assertSame(['handle' => 'anna', 'name' => 'Anna Bergen', 'avatar_url' => null], $item['author']);
        $this->assertArrayHasKey('distance_km', $item);
    }

    public function test_nothing_beyond_the_presenter_whitelist_leaks(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8);

        $this->loginAs(self::OTHER);
        $item = $this->discover(self::AREA)->assertOk()->json('routes.0');

        foreach (['owner_id', 'map_id', 'locations', 'meta', 'path_hash', 'path_polyline', 'mission_id', 'bijlage', 'callsign', 'eenheid'] as $secret) {
            $this->assertArrayNotHasKey($secret, $item);
        }
        $this->assertStringNotContainsString('eigenaar@example.test', json_encode($item));
        $this->assertStringNotContainsString('geheim', json_encode($item));
        $this->assertEqualsCanonicalizing(['handle', 'name', 'avatar_url'], array_keys($item['author']));
    }

    public function test_own_routes_are_not_listed(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8);

        $this->loginAs(self::OWNER);

        $this->assertSame([], $this->discover(self::AREA)->assertOk()->json('routes'));
    }

    public function test_only_published_routes_of_enabled_profiles_are_found(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8, ['title' => 'Niet gepubliceerd', 'is_public' => false, 'published_at' => null]);

        // De maker zet zijn profiel uit: de gepubliceerde vlag blijft staan, maar niets is meer vindbaar.
        $this->profile(self::THIRD, 'derde', enabled: false);
        $this->route(self::THIRD, 45.7, 6.7, ['title' => 'Profiel uit']);

        $this->loginAs(self::OTHER);

        $this->assertSame([], $this->discover(self::AREA)->assertOk()->json('routes'));
    }

    public function test_organisation_routes_are_never_found(): void
    {
        $this->profile(self::OWNER, 'anna');
        $route = $this->route(self::OWNER, 45.8, 6.8);
        DB::table('route_maps')->where('id', $route->id)->update(['connect_organization_id' => 5]);

        $this->loginAs(self::OTHER);

        $this->assertSame([], $this->discover(self::AREA)->assertOk()->json('routes'));
    }

    public function test_only_routes_overlapping_the_area_are_found(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8, ['title' => 'Hier']);
        $this->route(self::OWNER, 51.5, 5.5, ['title' => 'Ver weg']);
        // Zonder waypoints geen omhullende: niet te vinden.
        $leeg = RouteMap::create(['map_id' => $this->mapId, 'owner_id' => (string) self::OWNER, 'title' => 'Leeg', 'locations' => [], 'is_public' => true, 'published_at' => now()]);

        $this->loginAs(self::OTHER);
        $titles = array_column($this->discover(self::AREA)->assertOk()->json('routes'), 'title');

        $this->assertSame(['Hier'], $titles);
        $this->assertNull($leeg->fresh()->bbox_min_lat);
    }

    public function test_a_route_that_only_crosses_the_edge_of_the_area_is_found(): void
    {
        $this->profile(self::OWNER, 'anna');
        // Het pad loopt van lon 5.9 tot 6.0 en raakt zo net de rand (6.0) van het gebied.
        $this->route(self::OWNER, 45.5, 5.98);

        $this->loginAs(self::OTHER);

        $this->assertCount(1, $this->discover(self::AREA)->assertOk()->json('routes'));
    }

    public function test_search_by_point_and_radius(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8);

        $this->loginAs(self::OTHER);

        $this->assertCount(1, $this->discover(['lat' => 45.81, 'lon' => 6.81, 'radius_km' => 5])->assertOk()->json('routes'));
        $this->assertCount(0, $this->discover(['lat' => 46.5, 'lon' => 6.81, 'radius_km' => 5])->assertOk()->json('routes'));
    }

    public function test_the_sport_filter_and_nearest_first_order_and_limit(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.20, 6.20, ['title' => 'Ver']);
        $this->route(self::OWNER, 45.80, 6.80, ['title' => 'Dichtbij', 'sport' => 'mtb']);
        $this->route(self::OWNER, 45.60, 6.50, ['title' => 'Midden']);

        $this->loginAs(self::OTHER);
        $center = ['lat' => 45.8, 'lon' => 6.8, 'radius_km' => 100];

        $this->assertSame(['Dichtbij', 'Midden', 'Ver'], array_column($this->discover($center)->json('routes'), 'title'));
        $this->assertSame(['Dichtbij'], array_column($this->discover($center + ['sport' => 'mtb'])->json('routes'), 'title'));
        $this->assertSame(['Dichtbij'], array_column($this->discover($center + ['limit' => 1])->json('routes'), 'title'));
    }

    public function test_a_huge_area_is_cut_down_around_its_centre(): void
    {
        $this->loginAs(self::OTHER);

        $bbox = $this->discover(['bbox' => '-100,-60,100,60'])->assertOk()->json('bbox');

        $this->assertLessThanOrEqual(1.5 + 1e-9, $bbox[2] - $bbox[0]);
        $this->assertEqualsWithDelta(0, ($bbox[0] + $bbox[2]) / 2, 1e-9);
    }

    public function test_a_route_without_a_sport_or_path_is_still_listed_but_flagged(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8, ['path_polyline' => null, 'elevation_profile' => null, 'ascent_m' => null, 'sport' => null]);

        $this->loginAs(self::OTHER);
        $item = $this->discover(self::AREA)->assertOk()->json('routes.0');

        $this->assertFalse($item['has_path']);
        $this->assertNull($item['sport']);
        $this->assertGreaterThanOrEqual(2, count($item['preview']), 'de lijn langs de waypoints');
    }

    // ── invoer en toegang ────────────────────────────────────────────────

    public function test_it_is_publicly_readable_without_a_login(): void
    {
        // Ontdekken is bewust publiek: milmap.nl toont dezelfde routes. Alleen wat de maker/OSM openbaar maakte.
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8);
        $this->profile(self::OTHER, 'ben', false);          // profiel uit: zijn route blijft verborgen
        $this->route(self::OTHER, 45.7, 6.7);
        $this->route(self::OWNER, 45.6, 6.6, ['is_public' => false, 'title' => 'Prive']);
        $this->trail(45.5, 6.5);

        $middleware = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/routes/discover'))->gatherMiddleware();
        $this->assertEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'auth')));

        $items = collect($this->discover(self::AREA)->assertOk()->json('routes'));

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['anna', null], $items->pluck('author.handle')->all());
        $this->assertStringNotContainsString('geheim', $items->toJson());
        $this->assertStringNotContainsString('Prive', $items->toJson());
    }

    public function test_it_needs_an_area(): void
    {
        $this->loginAs(self::OTHER);

        $this->discover([])->assertUnprocessable();
        $this->discover(['bbox' => '7.5,46.0,6.0,45.0'])->assertUnprocessable();   // omgedraaid
        $this->discover(['bbox' => 'onzin'])->assertUnprocessable();
        $this->discover(['lat' => 45.0, 'lon' => 6.0, 'radius_km' => 500])->assertUnprocessable();
        $this->discover(self::AREA + ['sport' => 'boot'])->assertUnprocessable();
        $this->discover(self::AREA + ['limit' => 500])->assertUnprocessable();
    }

    // ── daarna gebruiken ─────────────────────────────────────────────────

    public function test_a_found_route_can_be_saved_as_an_ordinary_route(): void
    {
        $this->profile(self::OWNER, 'anna');
        $route = $this->route(self::OWNER, 45.8, 6.8);

        $this->loginAs(self::OTHER);
        $found = $this->discover(self::AREA)->assertOk()->json('routes.0');
        $res = $this->postJson("/api/v1/profiles/{$found['author']['handle']}/routes/{$found['id']}/clone")->assertCreated();

        $copy = RouteMap::findOrFail($res->json('routemap.id'));
        $this->assertSame((string) self::OTHER, (string) $copy->owner_id);
        $this->assertSame('route', $copy->meta['kind'], 'past bij de keuze gewone route / routekaart');
        $this->assertSame($route->path_polyline, $copy->path_polyline);
        $this->assertSame((string) self::OWNER, (string) $copy->cloned_from_user_id);
    }
    public function test_distance_filters_apply_before_results_are_returned(): void
    {
        $this->profile(self::OWNER, 'anna');
        $route = $this->route(self::OWNER, 45.8, 6.8);
        $route->update(['total_distance' => 10000]);
        $this->loginAs(self::OTHER);
        $this->discover(self::AREA + ['max_km' => 8])->assertOk()->assertJsonCount(0, 'routes');
        $this->discover(self::AREA + ['max_km' => 11])->assertOk()->assertJsonCount(1, 'routes');
        $this->discover(self::AREA + ['min_km' => 12, 'max_km' => 8])->assertUnprocessable();
    }

    // ── OpenStreetMap-routes (geïmporteerd in trail_routes) ──────────────

    private int $osmCounter = 0;

    /** Een OSM-rondje van 41 punten rond (lat, lon), zoals milmap:import-routes het opslaat. */
    private function trail(float $lat, float $lon, array $extra = []): TrailRoute
    {
        $line = [];
        for ($i = 0; $i <= 40; $i++) {
            $a = $i / 40 * 2 * M_PI;
            $line[] = [round($lon + 0.01 * sin($a), 6), round($lat + 0.01 * cos($a), 6)];
        }
        $this->osmCounter++;

        return TrailRoute::create($extra + [
            'source' => 'osm', 'source_id' => 5000 + $this->osmCounter, 'slug' => 'heiderondje-' . $this->osmCounter,
            'name' => 'Heiderondje', 'type' => 'wandelen', 'country' => 'NL', 'province' => 'noord-brabant',
            'distance_km' => 6.5, 'is_loop' => true, 'segments' => 1,
            'start_lat' => $lat + 0.01, 'start_lon' => $lon,
            'min_lat' => $lat - 0.01, 'min_lon' => $lon - 0.01, 'max_lat' => $lat + 0.01, 'max_lon' => $lon + 0.01,
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => [$line]],
            'gpx_path' => 'routes/wandelen/geheim.gpx',
            'source_url' => 'https://www.openstreetmap.org/relation/' . (5000 + $this->osmCounter),
            'attribution' => '© OpenStreetMap contributors, ODbL 1.0',
        ]);
    }

    public function test_all_results_includes_more_than_the_candidate_and_display_limits(): void
    {
        $this->loginAs(self::OTHER);
        for ($i = 0; $i < 155; $i++) {
            $this->trail(45.5, 6.5, ['distance_km' => $i < 153 ? 6.5 : 20]);
        }
        $this->discover(self::AREA)->assertOk()->assertJsonCount(30, 'routes');
        $this->discover(self::AREA + ['all' => 1])->assertOk()->assertJsonCount(155, 'routes');
        $this->discover(self::AREA + ['all' => 1, 'max_km' => 10])->assertOk()->assertJsonCount(153, 'routes');
    }

    public function test_imported_osm_routes_are_listed_between_those_of_users_without_leaking_import_fields(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8);
        $osm = $this->trail(45.5, 6.5);

        $this->loginAs(self::OTHER);
        $items = collect($this->discover(self::AREA)->assertOk()->json('routes'));

        $this->assertCount(2, $items);
        $item = $items->firstWhere('source', 'osm');
        $this->assertSame('osm-' . $osm->id, $item['id']);
        $this->assertSame($osm->slug, $item['slug']);
        $this->assertSame('hike', $item['sport']);
        $this->assertSame(6500.0, (float) $item['total_distance']);
        $this->assertSame(['handle' => null, 'name' => 'OpenStreetMap', 'avatar_url' => null], $item['author']);
        $this->assertSame('© OpenStreetMap contributors, ODbL 1.0', $item['attribution']);
        $this->assertTrue($item['has_path']);
        $this->assertCount(40, $item['preview']);
        $this->assertArrayNotHasKey('gpx_path', $item);
        $this->assertArrayNotHasKey('source_id', $item);
        $this->assertStringNotContainsString('geheim', json_encode($item));
    }

    public function test_osm_preview_is_stored_on_save_and_the_list_never_loads_the_geometry(): void
    {
        $osm = $this->trail(45.5, 6.5);

        $stored = $osm->fresh();
        $this->assertCount(40, $stored->preview);
        $this->assertSame(41, $stored->point_count);
        // [lat, lon], de eerste en laatste punten van het rondje blijven behouden.
        $this->assertEquals([45.51, 6.5], $stored->preview[0]);

        $this->loginAs(self::OTHER);
        DB::enableQueryLog();
        $item = $this->discover(self::AREA)->assertOk()->json('routes.0');
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'trail_routes'));
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        $queries->each(fn ($q) => $this->assertStringNotContainsString('geometry', $q));
        $this->assertSame($stored->preview, $item['preview']);
        $this->assertSame(41, $item['point_count']);
    }

    public function test_osm_preview_follows_the_geometry_when_it_changes(): void
    {
        $osm = $this->trail(45.5, 6.5);
        $osm->update(['geometry' => ['type' => 'MultiLineString', 'coordinates' => [[[6.5, 45.5], [6.6, 45.6], [6.7, 45.7]]]]]);

        $stored = $osm->fresh();
        $this->assertSame(3, $stored->point_count);
        $this->assertEquals([[45.5, 6.5], [45.6, 6.6], [45.7, 6.7]], $stored->preview);
    }

    public function test_a_route_without_a_stored_preview_still_gets_one_in_the_list(): void
    {
        $osm = $this->trail(45.5, 6.5);
        DB::table('trail_routes')->where('id', $osm->id)->update(['preview' => null, 'point_count' => 0]);

        $this->loginAs(self::OTHER);
        $item = $this->discover(self::AREA)->assertOk()->json('routes.0');

        $this->assertCount(40, $item['preview']);
        $this->assertSame(41, $item['point_count']);
        // Alleen berekend voor het antwoord: de rij zelf blijft ongemoeid.
        $this->assertNull(DB::table('trail_routes')->where('id', $osm->id)->value('preview'));
    }

    public function test_own_routes_stay_out_of_discover_even_when_the_default_guard_is_not_sanctum(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->profile(self::OTHER, 'ben');
        $this->route(self::OWNER, 45.8, 6.8);
        $this->route(self::OTHER, 45.7, 6.7);

        // Buiten een auth:sanctum-groep (zoals de publieke route) is de standaard-guard `web`: die kent de bezoeker niet.
        $this->loginAs(self::OTHER);
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->assertNull(\Illuminate\Support\Facades\Auth::id());

        $items = $this->discover(self::AREA)->assertOk()->json('routes');

        $this->assertCount(1, $items);
        $this->assertSame('anna', $items[0]['author']['handle']);
    }

    public function test_all_gives_full_items_for_the_nearest_routes_and_compact_markers_for_the_rest(): void
    {
        $this->profile(self::OWNER, 'anna');
        $far = $this->route(self::OWNER, 45.8, 6.8); // verder van het midden dan alle OSM-routes hieronder
        for ($i = 0; $i < 202; $i++) {
            $this->trail(45.5, 6.5, ['distance_km' => 6.5]);
        }

        $this->loginAs(self::OTHER);
        $res = $this->discover(self::AREA + ['all' => 1])->assertOk();

        $this->assertCount(200, $res->json('routes'));
        $this->assertSame(203, $res->json('total'));
        $this->assertTrue($res->json('truncated'));
        $this->assertSame(['osm'], array_values(array_unique(array_column($res->json('routes'), 'source'))));

        $markers = collect($res->json('markers'));
        $this->assertCount(3, $markers);
        $this->assertSame($far->id, $markers->firstWhere('source', 'milmap')['id']);

        // Een marker: alleen wat nodig is voor de kaart en om hem later op te vragen.
        $osm = $markers->firstWhere('source', 'osm');
        $this->assertSame(['id', 'lat', 'lon', 'slug', 'source', 'sport'], collect($osm)->keys()->sort()->values()->all());
        $this->assertStringStartsWith('osm-', $osm['id']);
        $this->assertSame('hike', $osm['sport']);
        $this->assertEqualsWithDelta(45.51, $osm['lat'], 0.0001);   // het startpunt, niet het midden
        $this->assertEqualsWithDelta(6.5, $osm['lon'], 0.0001);

        $mine = $markers->firstWhere('source', 'milmap');
        $this->assertSame('hike', $mine['sport']);
        $this->assertEqualsWithDelta(45.8, $mine['lat'], 0.0001);   // eerste waypoint
        $this->assertEqualsWithDelta(6.8, $mine['lon'], 0.0001);
        $this->assertStringStartsWith('naar-de-top-', $mine['slug']);
        $this->assertStringNotContainsString('geheim', json_encode($markers));
    }

    public function test_all_with_few_routes_has_no_markers_and_is_not_truncated(): void
    {
        $this->profile(self::OWNER, 'anna');
        $this->route(self::OWNER, 45.8, 6.8);
        $this->trail(45.5, 6.5);

        $this->loginAs(self::OTHER);
        $res = $this->discover(self::AREA + ['all' => 1])->assertOk();

        $this->assertCount(2, $res->json('routes'));
        $this->assertSame([], $res->json('markers'));
        $this->assertFalse($res->json('truncated'));
        $this->assertSame(2, $res->json('total'));
    }

    public function test_without_all_the_answer_has_no_markers(): void
    {
        $this->trail(45.5, 6.5);

        $this->loginAs(self::OTHER);
        $json = $this->discover(self::AREA)->assertOk()->json();

        $this->assertArrayNotHasKey('markers', $json);
        $this->assertArrayNotHasKey('truncated', $json);
        $this->assertCount(1, $json['routes']);
    }

    public function test_markers_follow_the_filters(): void
    {
        for ($i = 0; $i < 201; $i++) {
            $this->trail(45.5, 6.5, ['type' => $i < 150 ? 'wandelen' : 'mtb', 'distance_km' => 6.5]);
        }

        $this->loginAs(self::OTHER);
        $mtb = $this->discover(self::AREA + ['all' => 1, 'sport' => 'mtb'])->assertOk();
        $this->assertCount(51, $mtb->json('routes'));
        $this->assertSame([], $mtb->json('markers'));

        $hike = $this->discover(self::AREA + ['all' => 1, 'sport' => 'hike'])->assertOk();
        $this->assertCount(150, $hike->json('routes'));
        $this->assertFalse($hike->json('truncated'));
    }

    public function test_osm_routes_are_found_per_sport_for_every_imported_type(): void
    {
        $this->trail(45.5, 6.5);
        $this->trail(45.5, 6.5, ['type' => 'fietsen', 'country' => 'DE', 'province' => 'bayern', 'distance_km' => 40]);
        $this->trail(45.5, 6.5, ['type' => 'mtb', 'distance_km' => 25]);
        $this->trail(45.5, 6.5, ['type' => 'hardlopen', 'distance_km' => 8]);
        $this->loginAs(self::OTHER);

        $this->discover(self::AREA + ['all' => 1])->assertJsonCount(4, 'routes');
        foreach (['hike', 'bike', 'mtb', 'run'] as $sport) {
            $items = $this->discover(self::AREA + ['all' => 1, 'sport' => $sport])->assertOk()->json('routes');
            $this->assertCount(1, $items, $sport);
            $this->assertSame($sport, $items[0]['sport']);
        }
        // Duur volgt het tempo van de sport: 40 km fietsen ≈ 141 min, 8 km hardlopen ≈ 53 min.
        $this->discover(self::AREA + ['all' => 1, 'sport' => 'bike', 'max_minutes' => 150])->assertJsonCount(1, 'routes');
        $this->discover(self::AREA + ['all' => 1, 'sport' => 'run', 'max_minutes' => 50])->assertJsonCount(0, 'routes');
    }

    public function test_osm_routes_follow_the_filters(): void
    {
        $this->trail(45.5, 6.5);
        $this->loginAs(self::OTHER);

        $this->discover(self::AREA)->assertJsonCount(1, 'routes');
        $this->discover(self::AREA + ['sport' => 'hike'])->assertJsonCount(1, 'routes');
        $this->discover(self::AREA + ['sport' => 'mtb'])->assertJsonCount(0, 'routes');
        $this->discover(self::AREA + ['sport' => 'bike'])->assertJsonCount(0, 'routes');
        $this->discover(self::AREA + ['max_km' => 5])->assertJsonCount(0, 'routes');
        $this->discover(self::AREA + ['max_km' => 8])->assertJsonCount(1, 'routes');
        $this->discover(self::AREA + ['route_type' => 'point_to_point'])->assertJsonCount(0, 'routes');
        $this->discover(self::AREA + ['route_type' => 'loop'])->assertJsonCount(1, 'routes');
        // OSM kent geen moeilijkheid of hoogtemeters: zulke filters sluiten ze uit.
        $this->discover(self::AREA + ['difficulty' => 'easy'])->assertJsonCount(0, 'routes');
        $this->discover(self::AREA + ['min_ascent' => 10])->assertJsonCount(0, 'routes');
    }

    public function test_an_osm_route_has_a_detail_with_a_path_and_an_unpublished_one_is_gone(): void
    {
        $osm = $this->trail(45.5, 6.5);
        $hidden = $this->trail(45.6, 6.6, ['is_published' => false]);
        $this->loginAs(self::OTHER);

        $this->discover(self::AREA)->assertJsonCount(1, 'routes');

        $detail = $this->getJson("/api/v1/trail-routes/{$osm->slug}")->assertOk()->json('route');
        $this->assertSame('osm', $detail['source']);
        $this->assertNotEmpty($detail['path']);
        $this->assertGreaterThanOrEqual(2, count(RouteGeometry::decode($detail['path'])));
        $this->assertCount(1, $detail['locations']);
        $this->assertArrayNotHasKey('gpx_path', $detail);

        $this->getJson("/api/v1/trail-routes/{$hidden->slug}")->assertNotFound();
        $this->postJson("/api/v1/trail-routes/{$hidden->slug}/clone")->assertNotFound();
    }

    public function test_an_osm_route_can_be_saved_as_an_ordinary_route_that_keeps_its_source(): void
    {
        $osm = $this->trail(45.5, 6.5);
        $this->loginAs(self::OTHER);

        $res = $this->postJson("/api/v1/trail-routes/{$osm->slug}/clone")->assertCreated();
        $this->assertNull($res->json('source'), 'een OSM-route heeft geen maker');

        $copy = RouteMap::findOrFail($res->json('routemap.id'));
        $this->assertSame((string) self::OTHER, (string) $copy->owner_id);
        $this->assertSame('route', $copy->meta['kind']);
        $this->assertSame('hike', $copy->sport);
        $this->assertSame('osm', $copy->source);
        $this->assertSame('© OpenStreetMap contributors, ODbL 1.0', $copy->attribution);
        $this->assertSame('trail_route', $copy->cloned_from_type);
        $this->assertSame((string) $osm->id, (string) $copy->cloned_from_id);
        $this->assertNull($copy->cloned_from_user_id);
        $this->assertNotEmpty($copy->path_polyline);
        $this->assertNotNull($copy->bbox_min_lat, 'de omhullende volgt het pad');
        $this->assertGreaterThanOrEqual(3, count($copy->locations));
        $this->assertSame(6500.0, (float) $copy->total_distance);

        // Het aantal gebruikers dat de route overnam telt mee.
        $this->assertSame(1, $this->discover(self::AREA)->json('routes.0.clones'));
    }

    public function test_a_generated_loop_is_presented_as_made_by_milmap_and_has_no_overpass_extras(): void
    {
        $loop = $this->trail(45.5, 6.5, [
            'source' => 'generated', 'source_id' => 8914871105, 'type' => 'hardlopen', 'name' => '5 km hardlooprondje Oosterbos, Emmen',
            'source_url' => 'https://www.openstreetmap.org/?mlat=45.51&mlon=6.5#map=15/45.51/6.5',
            'attribution' => 'Route gegenereerd door MilMap op basis van © OpenStreetMap contributors, ODbL 1.0',
        ]);
        $this->loginAs(self::OTHER);

        $item = $this->discover(self::AREA + ['sport' => 'run'])->assertOk()->assertJsonCount(1, 'routes')->json('routes.0');
        $this->assertSame('run', $item['sport']);
        $this->assertTrue($item['generated']);
        $this->assertSame('osm', $item['source'], 'blijft een catalogusroute voor de app');
        $this->assertSame('MilMap', $item['author']['name']);
        $this->assertStringContainsString('OpenStreetMap contributors', $item['attribution']);
        $this->assertArrayNotHasKey('source_id', $item);

        // Een gemapte OSM-route is dat niet.
        $this->trail(45.6, 6.6, ['type' => 'hardlopen']);
        $osm = collect($this->discover(self::AREA + ['sport' => 'run'])->json('routes'))->firstWhere('title', 'Heiderondje');
        $this->assertFalse($osm['generated']);
        $this->assertSame('OpenStreetMap', $osm['author']['name']);

        // Extras vragen Overpass om een OSM-relatie: die is er niet. Geen netwerk, geen "pending".
        \Illuminate\Support\Facades\Http::fake();
        $extras = $this->getJson("/api/v1/trail-routes/{$loop->slug}/extras")->assertOk()->json();
        $this->assertSame([], $extras['waytypes']);
        $this->assertEmpty($extras['pending'] ?? false);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
