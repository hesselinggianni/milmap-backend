<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Map;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Gemarkeerde gebieden uit een grid of een straal: het vlak staat gewoon in
 * metadata.saluteGeometry, de waarde in metadata.saluteShape. De backend bewaart
 * beide ongewijzigd en weigert een kapotte waarde.
 */
class MarkedAreaShapeTest extends TestCase
{
    private string $mapId = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('maps', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('owner_id'); $t->string('title'); $t->timestamps(); $t->softDeletes();
        });
        foreach (['2026_05_17_113451_create_reports_table.php', '2026_07_18_130000_create_report_revisions_table.php',
            '2026_10_14_170000_add_client_request_ids_to_reports_and_locations.php'] as $migration) {
            if ($migration === '2026_10_14_170000_add_client_request_ids_to_reports_and_locations.php') {
                (require database_path('migrations/2026_05_17_112502_create_locations_table.php'))->up();
            }
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('users')->insert([['id' => 1, 'name' => 'One']]);
        DB::table('maps')->insert(['id' => $this->mapId, 'owner_id' => 1, 'title' => 'Test']);
        $this->actingAs(User::findOrFail(1));
        Gate::define('update', fn (User $user, Map $map) => (int) $map->owner_id === (int) $user->id);
    }

    private function payload(array $metadata): array
    {
        return [
            'map_id' => $this->mapId, 'category' => 'threat', 'type' => 'infantry',
            'latitude' => 51.4686, 'longitude' => 5.3252, 'metadata' => $metadata,
        ];
    }

    private function square(): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[
            [5.3177865, 51.4642393], [5.3321699, 51.4639539], [5.3326283, 51.4729383], [5.318242, 51.4732237], [5.3177865, 51.4642393],
        ]]];
    }

    private function store(array $metadata): array
    {
        return (new ReportController())->store(Request::create('/', 'POST', $this->payload($metadata)))->getData(true);
    }

    public function test_a_grid_area_keeps_its_polygon_and_its_grid(): void
    {
        $shape = ['kind' => 'grid', 'grid' => '31UFT6104', 'size' => 1000];
        $stored = $this->store(['title' => '31U FT 61 04', 'saluteGeometry' => $this->square(), 'saluteShape' => $shape]);

        $this->assertSame($shape, $stored['metadata']['saluteShape']);
        $this->assertSame($this->square(), $stored['metadata']['saluteGeometry']);
        $this->assertSame('31U FT 61 04', $stored['metadata']['title']);

        $fromDatabase = DB::table('reports')->where('id', $stored['id'])->value('metadata');
        $this->assertSame($shape, json_decode($fromDatabase, true)['saluteShape']);
    }

    public function test_a_circle_around_a_waypoint_keeps_its_radius_and_waypoint(): void
    {
        $shape = ['kind' => 'circle', 'radius' => 1500, 'center' => [5.4697, 51.4416], 'waypointId' => 1700000000001];
        $stored = $this->store(['saluteGeometry' => $this->square(), 'saluteShape' => $shape]);
        $this->assertEquals($shape, $stored['metadata']['saluteShape']);
    }

    public function test_a_drawn_area_without_a_shape_is_unchanged(): void
    {
        $stored = $this->store(['saluteGeometry' => $this->square()]);
        $this->assertArrayNotHasKey('saluteShape', $stored['metadata']);
        $this->assertSame($this->square(), $stored['metadata']['saluteGeometry']);
    }

    public function test_updating_a_grid_replaces_the_polygon_and_the_shape(): void
    {
        $stored = $this->store(['saluteGeometry' => $this->square(), 'saluteShape' => ['kind' => 'grid', 'grid' => '31UFT6104', 'size' => 1000]]);
        $moved = ['type' => 'Polygon', 'coordinates' => [[[5.332, 51.473], [5.347, 51.473], [5.347, 51.482], [5.332, 51.482], [5.332, 51.473]]]];
        $request = Request::create('/', 'PUT', [
            'latitude' => 51.4775, 'longitude' => 5.3395,
            'metadata' => ['saluteGeometry' => $moved, 'saluteShape' => ['kind' => 'grid', 'grid' => '31UFT6205', 'size' => 1000]],
        ]);
        $updated = (new ReportController())->update($request, $stored['id'])->getData(true);
        $this->assertSame('31UFT6205', $updated['metadata']['saluteShape']['grid']);
        $this->assertSame($moved, $updated['metadata']['saluteGeometry']);
        $this->assertEquals(51.4775, $updated['latitude']);
    }

    public function test_a_broken_shape_is_refused(): void
    {
        $bad = [
            'unknown kind' => ['kind' => 'blob'],
            'no kind' => ['grid' => '31UFT6104'],
            'grid missing' => ['kind' => 'grid'],
            'grid not mgrs' => ['kind' => 'grid', 'grid' => 'hallo'],
            'grid odd digits' => ['kind' => 'grid', 'grid' => '31UFT610'],
            'grid band I' => ['kind' => 'grid', 'grid' => '31IFT6104'],
            'circle without radius' => ['kind' => 'circle', 'center' => [5.4, 51.4]],
            'circle radius zero' => ['kind' => 'circle', 'radius' => 0, 'center' => [5.4, 51.4]],
            'circle radius too large' => ['kind' => 'circle', 'radius' => 500001, 'center' => [5.4, 51.4]],
            'circle radius text' => ['kind' => 'circle', 'radius' => 'groot', 'center' => [5.4, 51.4]],
            'circle without center' => ['kind' => 'circle', 'radius' => 500],
            'circle center one value' => ['kind' => 'circle', 'radius' => 500, 'center' => [5.4]],
            'circle center out of range' => ['kind' => 'circle', 'radius' => 500, 'center' => [5.4, 95]],
        ];
        foreach ($bad as $name => $shape) {
            try {
                $this->store(['saluteGeometry' => $this->square(), 'saluteShape' => $shape]);
                $this->fail("'$name' had to be refused");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors(), $name);
            }
        }
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_a_broken_shape_is_also_refused_when_editing(): void
    {
        $stored = $this->store(['saluteGeometry' => $this->square()]);
        $this->expectException(ValidationException::class);
        (new ReportController())->update(
            Request::create('/', 'PUT', ['metadata' => ['saluteShape' => ['kind' => 'circle', 'radius' => -5, 'center' => [5.4, 51.4]]]]),
            $stored['id']
        );
    }
}
