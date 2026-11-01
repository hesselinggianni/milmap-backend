<?php

namespace Tests\Feature;

use App\Models\TrailRoute;
use App\Services\RouteImport\RouteImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * milmap:import-routes: OSM-routes (op relatie) én door MilMap gegenereerde rondjes
 * (op bronnummer) komen in dezelfde tabel, maar blijven uit elkaar te houden.
 */
class TrailRouteImporterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require base_path('database/migrations/2026_10_30_000000_create_trail_routes_table.php'))->up();
        (require base_path('database/migrations/2026_11_01_120000_add_preview_to_trail_routes_table.php'))->up();

        Storage::fake('local');
        $this->dir = sys_get_temp_dir() . '/trail-import-' . uniqid();
        mkdir($this->dir . '/hardlopen/drenthe', 0777, true);
        $points = '';
        foreach ([[52.99, 6.56], [53.00, 6.57], [53.00, 6.59], [52.99, 6.56]] as [$lat, $lon]) {
            $points .= "<trkpt lat=\"{$lat}\" lon=\"{$lon}\"/>";
        }
        file_put_contents($this->dir . '/hardlopen/drenthe/rondje.gpx',
            '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>' . $points . '</trkseg></trk></gpx>');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/hardlopen/drenthe/rondje.gpx');
        @rmdir($this->dir . '/hardlopen/drenthe');
        @rmdir($this->dir . '/hardlopen');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function import(array $item): string
    {
        return app(RouteImporter::class)->import($item + [
            'name' => '5 km hardlooprondje Oosterbos, Emmen', 'type' => 'hardlopen', 'province' => 'drenthe',
            'gpx_file' => 'hardlopen/drenthe/rondje.gpx', 'distance_km' => 5.1, 'is_loop' => true,
        ], $this->dir, 'local', 0.0, false);
    }

    private function generated(array $extra = []): array
    {
        return $extra + [
            'source' => 'generated', 'source_id' => 89148711 * 100 + 5,
            'source_url' => 'https://www.openstreetmap.org/?mlat=52.99&mlon=6.56#map=15/52.99/6.56',
            'attribution' => 'Route gegenereerd door MilMap op basis van © OpenStreetMap contributors, ODbL 1.0',
        ];
    }

    public function test_a_generated_loop_is_stored_with_its_own_source_and_attribution(): void
    {
        $this->assertSame('created', $this->import($this->generated()));

        $route = TrailRoute::firstOrFail();
        $this->assertSame('generated', $route->source);
        $this->assertSame(8914871105, (int) $route->source_id);
        $this->assertSame('hardlopen', $route->type);
        $this->assertSame('https://www.openstreetmap.org/?mlat=52.99&mlon=6.56#map=15/52.99/6.56', $route->source_url);
        $this->assertStringContainsString('MilMap', $route->attribution);
        $this->assertStringContainsString('OpenStreetMap', $route->attribution);
        Storage::disk('local')->assertExists('routes/hardlopen/drenthe/rondje.gpx');

        // Herhaalrun verandert niets.
        $this->assertSame('unchanged', $this->import($this->generated()));
        $this->assertSame(1, TrailRoute::count());
    }

    public function test_an_osm_route_keeps_the_relation_url_and_standard_attribution(): void
    {
        $this->import(['osm_relation' => 5650650, 'name' => 'Running zuidas']);

        $route = TrailRoute::firstOrFail();
        $this->assertSame('osm', $route->source);
        $this->assertSame('https://www.openstreetmap.org/relation/5650650', $route->source_url);
        $this->assertSame('© OpenStreetMap contributors, ODbL 1.0', $route->attribution);
    }

    public function test_a_generated_loop_and_an_osm_route_may_share_a_number(): void
    {
        $this->import(['osm_relation' => 777, 'slug' => 'a']);
        $this->import($this->generated(['source_id' => 777, 'slug' => 'b']));

        $this->assertSame(2, TrailRoute::count());
    }

    public function test_a_generated_loop_must_credit_openstreetmap_and_link_over_https(): void
    {
        foreach ([
            ['attribution' => 'Gegenereerd door MilMap'],
            ['source_url' => 'http://example.test/route'],
            ['source_url' => null],
            ['source_id' => 0],
        ] as $bad) {
            try {
                $this->import($this->generated($bad));
                $this->fail('verwacht een afgewezen import voor ' . json_encode($bad));
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, TrailRoute::count());
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->import($this->generated(['source' => 'strava']));
    }
}
