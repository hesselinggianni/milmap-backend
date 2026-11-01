<?php

namespace Tests\Feature;

use App\Models\TrailRoute;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Routes per land: aantal geïmporteerde OSM-routes per land en per type.
 */
class AdminTrailRouteStatsTest extends TestCase
{
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');

        // Echte migratie, zodat de test het schema niet nabootst.
        (require base_path('database/migrations/2026_10_30_000000_create_trail_routes_table.php'))->up();
        (require base_path('database/migrations/2026_11_01_120000_add_preview_to_trail_routes_table.php'))->up();
    }

    private function route(string $country, string $type, float $km = 5.0, bool $published = true): void
    {
        $this->counter++;
        TrailRoute::create([
            'source' => 'osm', 'source_id' => $this->counter, 'slug' => 'route-' . $this->counter,
            'name' => 'Route ' . $this->counter, 'type' => $type, 'country' => $country,
            'distance_km' => $km, 'start_lat' => 52, 'start_lon' => 5,
            'min_lat' => 52, 'min_lon' => 5, 'max_lat' => 52.1, 'max_lon' => 5.1,
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => []], 'gpx_path' => 'routes/x.gpx',
            'source_url' => 'https://www.openstreetmap.org/relation/' . $this->counter,
            'attribution' => '© OpenStreetMap contributors, ODbL 1.0', 'is_published' => $published,
        ]);
    }

    public function test_route_is_admin_protected(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/admin/trail-routes/stats'));
        $this->assertContains('admin.auth', $route->gatherMiddleware());
    }

    public function test_counts_routes_per_country_and_type_sorted_by_size(): void
    {
        $this->withoutMiddleware();
        $this->route('NL', 'wandelen', 6.5);
        $this->route('NL', 'wandelen', 3.25);
        $this->route('NL', 'mtb', 20);
        $this->route('BE', 'wandelen', 10);
        $this->route('BE', 'wandelen', 12, published: false);
        $this->route('DE', 'mtb', 40);
        $this->route('DE', 'mtb', 15);
        $this->route('DE', 'mtb', 7);
        $this->route('DE', 'mtb', 8);

        $response = $this->getJson('/api/v1/admin/trail-routes/stats')->assertOk()
            ->assertJsonPath('types', ['wandelen', 'fietsen', 'mtb', 'hardlopen']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // Grootste land eerst: DE (4) vóór NL (3) vóór BE (1 gepubliceerd).
        $response->assertJsonPath('countries.0.code', 'DE')
            ->assertJsonPath('countries.0.routes', 4)
            ->assertJsonPath('countries.0.types.mtb.routes', 4)
            ->assertJsonPath('countries.0.types.wandelen.routes', 0)
            ->assertJsonPath('countries.1.code', 'NL')
            ->assertJsonPath('countries.1.routes', 3)
            ->assertJsonPath('countries.1.types.wandelen.routes', 2)
            ->assertJsonPath('countries.1.types.wandelen.km', 9.8)
            ->assertJsonPath('countries.1.types.mtb.routes', 1)
            ->assertJsonPath('countries.2.code', 'BE');

        // Ongepubliceerde routes tellen niet mee in "routes" of km, maar staan apart als "hidden".
        $response->assertJsonPath('countries.2.routes', 1)
            ->assertJsonPath('countries.2.hidden', 1)
            ->assertJsonPath('countries.2.types.wandelen.routes', 1)
            ->assertJsonPath('countries.2.types.wandelen.hidden', 1)
            ->assertJsonPath('countries.2.types.wandelen.km', 10);

        $response->assertJsonPath('totals.routes', 8)
            ->assertJsonPath('totals.hidden', 1)
            ->assertJsonPath('totals.types.mtb.routes', 5)
            ->assertJsonPath('totals.types.wandelen.routes', 3);
    }

    public function test_empty_table_returns_empty_lists_but_still_lists_every_type(): void
    {
        $this->withoutMiddleware();

        $this->getJson('/api/v1/admin/trail-routes/stats')->assertOk()
            ->assertJsonPath('types', ['wandelen', 'fietsen', 'mtb', 'hardlopen'])
            ->assertJsonPath('countries', [])
            ->assertJsonPath('top_saved', [])
            ->assertJsonPath('totals.routes', 0)
            ->assertJsonPath('totals.saves', 0)
            ->assertJsonPath('totals.types.fietsen.routes', 0);
    }

    public function test_unexpected_types_in_the_data_are_listed_after_the_known_ones(): void
    {
        $this->withoutMiddleware();
        $this->route('NL', 'kanoen');

        $this->getJson('/api/v1/admin/trail-routes/stats')->assertOk()
            ->assertJsonPath('types', ['wandelen', 'fietsen', 'mtb', 'hardlopen', 'kanoen'])
            ->assertJsonPath('totals.types.kanoen.routes', 1);
    }

    private function routeMapsTable(): void
    {
        Schema::create('route_maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('cloned_from_type', 16)->nullable();
            $t->string('cloned_from_id', 36)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function save(?int $trailId, string $type = 'trail_route', bool $deleted = false): void
    {
        DB::table('route_maps')->insert([
            'id' => (string) Str::uuid(), 'cloned_from_type' => $type, 'cloned_from_id' => $trailId === null ? null : (string) $trailId,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    public function test_without_route_maps_nothing_is_saved(): void
    {
        $this->withoutMiddleware();
        $this->route('NL', 'wandelen');

        $this->getJson('/api/v1/admin/trail-routes/stats')->assertOk()
            ->assertJsonPath('totals.saves', 0)
            ->assertJsonPath('top_saved', []);
    }

    public function test_counts_how_often_each_route_was_saved_per_country_and_type(): void
    {
        $this->withoutMiddleware();
        $this->routeMapsTable();
        $this->route('NL', 'mtb');        // id 1
        $this->route('NL', 'wandelen');   // id 2
        $this->route('DE', 'fietsen');    // id 3
        $this->route('NL', 'wandelen');   // id 4: nooit opgeslagen

        $this->save(1); $this->save(1); $this->save(1);
        $this->save(2);
        $this->save(3); $this->save(3);
        $this->save(1, deleted: true);          // weggegooide kopie telt niet
        $this->save(2, type: 'user_route');     // kopie van een gebruikersroute, geen OSM-route
        $this->save(999);                       // OSM-route bestaat niet meer
        $this->save(null);                      // route_map zonder herkomst

        $response = $this->getJson('/api/v1/admin/trail-routes/stats')->assertOk();

        $response->assertJsonPath('totals.saves', 6)
            ->assertJsonPath('totals.types.mtb.saves', 3)
            ->assertJsonPath('totals.types.wandelen.saves', 1)
            ->assertJsonPath('totals.types.fietsen.saves', 2)
            ->assertJsonPath('totals.types.hardlopen.saves', 0)
            ->assertJsonPath('countries.0.code', 'NL')
            ->assertJsonPath('countries.0.saves', 4)
            ->assertJsonPath('countries.0.types.mtb.saves', 3)
            ->assertJsonPath('countries.1.code', 'DE')
            ->assertJsonPath('countries.1.saves', 2);

        // Meest opgeslagen eerst; routes zonder opslag staan er niet in.
        $response->assertJsonCount(3, 'top_saved')
            ->assertJsonPath('top_saved.0.id', 1)
            ->assertJsonPath('top_saved.0.saves', 3)
            ->assertJsonPath('top_saved.0.type', 'mtb')
            ->assertJsonPath('top_saved.0.country', 'NL')
            ->assertJsonPath('top_saved.1.saves', 2)
            ->assertJsonPath('top_saved.2.saves', 1);
    }

    public function test_top_saved_is_limited_to_ten(): void
    {
        $this->withoutMiddleware();
        $this->routeMapsTable();
        for ($i = 1; $i <= 12; $i++) {
            $this->route('NL', 'wandelen');
            $this->save($i);
        }

        $this->getJson('/api/v1/admin/trail-routes/stats')->assertOk()
            ->assertJsonCount(10, 'top_saved')
            ->assertJsonPath('totals.saves', 12);
    }
}
