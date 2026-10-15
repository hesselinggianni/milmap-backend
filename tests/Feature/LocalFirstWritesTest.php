<?php

namespace Tests\Feature;

use App\Http\Controllers\LocationController;
use App\Http\Controllers\ReportController;
use App\Models\Map;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocalFirstWritesTest extends TestCase
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
        foreach (['2026_05_17_112502_create_locations_table.php', '2026_05_17_113451_create_reports_table.php',
            '2026_07_18_130000_create_report_revisions_table.php', '2026_10_14_170000_add_client_request_ids_to_reports_and_locations.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('users')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        DB::table('maps')->insert(['id' => $this->mapId, 'owner_id' => 1, 'title' => 'Test']);
        $this->actingAs(User::findOrFail(1));
        Gate::define('update', fn (User $user, Map $map) => (int) $map->owner_id === (int) $user->id);
    }

    public function test_report_retry_returns_same_id_without_duplicating_revisions(): void
    {
        $controller = new ReportController();
        $payload = ['client_request_id' => 'retry-report', 'category' => 'threat', 'type' => 'infantry', 'latitude' => 52, 'longitude' => 5];
        $first = $controller->store(Request::create('/', 'POST', $payload))->getData(true);
        $second = $controller->store(Request::create('/', 'POST', $payload))->getData(true);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('report_revisions', 1);
        $this->actingAs(User::findOrFail(2));
        $other = $controller->store(Request::create('/', 'POST', $payload))->getData(true);
        $this->assertNotSame($first['id'], $other['id']);
    }

    public function test_marker_retry_does_not_overwrite_an_intervening_edit(): void
    {
        $controller = new LocationController();
        $payload = ['client_request_id' => 'retry-marker', 'name' => 'Original', 'latitude' => 52, 'longitude' => 5];
        $first = $controller->store(Request::create('/', 'POST', $payload), $this->mapId)->getData(true);
        DB::table('locations')->where('id', $first['id'])->update(['name' => 'Edited']);
        $second = $controller->store(Request::create('/', 'POST', $payload), $this->mapId)->getData(true);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('Edited', $second['name']);
        $this->assertDatabaseCount('locations', 1);
    }
}
