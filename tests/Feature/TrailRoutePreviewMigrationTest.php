<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrailRoutePreviewMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');

        (require base_path('database/migrations/2026_10_30_000000_create_trail_routes_table.php'))->up();
    }

    private function insert(int $n, array $geometry): void
    {
        DB::table('trail_routes')->insert([
            'source' => 'osm', 'source_id' => $n, 'slug' => "route-{$n}", 'name' => "Route {$n}", 'type' => 'wandelen',
            'distance_km' => 5, 'start_lat' => 52, 'start_lon' => 5,
            'min_lat' => 52, 'min_lon' => 5, 'max_lat' => 52.1, 'max_lon' => 5.1,
            'geometry' => json_encode($geometry), 'gpx_path' => 'routes/x.gpx',
            'source_url' => 'https://www.openstreetmap.org/relation/' . $n, 'attribution' => 'OSM',
        ]);
    }

    public function test_existing_routes_get_their_preview_from_the_longest_segment(): void
    {
        $long = [];
        for ($i = 0; $i < 100; $i++) {
            $long[] = [5 + $i / 1000, 52 + $i / 1000];
        }
        $this->insert(1, ['type' => 'MultiLineString', 'coordinates' => [[[5.0, 52.0], [5.1, 52.1]], $long]]);
        $this->insert(2, ['type' => 'MultiLineString', 'coordinates' => []]);

        (require base_path('database/migrations/2026_11_01_120000_add_preview_to_trail_routes_table.php'))->up();

        $first = DB::table('trail_routes')->where('source_id', 1)->first();
        $this->assertSame(100, (int) $first->point_count);
        $preview = json_decode($first->preview, true);
        $this->assertCount(40, $preview);
        $this->assertEquals([52.0, 5.0], $preview[0]);   // [lat, lon]
        $this->assertEquals([52.099, 5.099], $preview[39]);

        $empty = DB::table('trail_routes')->where('source_id', 2)->first();
        $this->assertSame(0, (int) $empty->point_count);
        $this->assertSame([], json_decode($empty->preview, true));
    }

    public function test_the_migration_can_be_rolled_back(): void
    {
        $migration = require base_path('database/migrations/2026_11_01_120000_add_preview_to_trail_routes_table.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('trail_routes', 'preview'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('trail_routes', 'preview'));
        $this->assertFalse(Schema::hasColumn('trail_routes', 'point_count'));
    }
}
