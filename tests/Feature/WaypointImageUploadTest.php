<?php

namespace Tests\Feature;

use App\Events\MapWaypointChanged;
use App\Http\Controllers\MapWaypointController;
use App\Models\Map;
use App\Models\MapWaypointImage;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WaypointImageUploadTest extends TestCase
{
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'broadcasting.default' => 'null']);
        DB::purge('sqlite');
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('owner_id');
            $t->string('title');
            $t->timestamps();
            $t->softDeletes();
        });
        (require database_path('migrations/2026_06_16_100000_create_map_waypoints_table.php'))->up();
        (require database_path('migrations/2026_07_18_120000_create_map_waypoint_images_table.php'))->up();
        $userId = DB::table('users')->insertGetId(['name' => 'Upload test']);
        $user = User::findOrFail($userId);
        $this->actingAs($user);
        Gate::define('update', fn (User $user, Map $map) => (int) $map->owner_id === (int) $user->id);
        $this->map = Map::create(['title' => 'Upload test', 'owner_id' => $user->id]);
        Storage::fake('public');
    }

    private function upload()
    {
        $request = Request::create('/upload', 'POST', ['lon' => 5.5, 'lat' => 52.1], [], [
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ]);
        return (new MapWaypointController)->uploadImage($request, $this->map->id, '1786567355963');
    }

    public function test_photo_is_saved_with_its_waypoint_and_returned_to_client(): void
    {
        $response = $this->upload();
        $this->assertSame(201, $response->status());
        $this->assertSame(1786567355963, $response->getData(true)['waypoint']['id']);
        $image = MapWaypointImage::firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $this->assertCount(1, $response->getData(true)['waypoint']['images']);
    }

    public function test_broadcast_outage_does_not_turn_a_saved_photo_into_a_failed_upload(): void
    {
        Event::listen(MapWaypointChanged::class, function () {
            throw new BroadcastException('Live-update service unavailable');
        });
        $this->assertSame(201, $this->upload()->status());
        $this->assertSame(1, MapWaypointImage::count());
        Storage::disk('public')->assertExists(MapWaypointImage::firstOrFail()->path);
    }

    public function test_storage_failure_returns_retryable_error_without_a_photo_record(): void
    {
        Storage::shouldReceive('disk')->with('public')->andReturn(
            \Mockery::mock()->shouldReceive('putFileAs')->andReturn(false)->getMock()
        );
        try {
            $this->upload();
            $this->fail('Expected storage failure');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(0, MapWaypointImage::count());
    }

    public function test_database_failure_removes_the_unregistered_file(): void
    {
        MapWaypointImage::creating(function () { throw new \RuntimeException('Database write failed'); });
        try {
            $this->upload();
            $this->fail('Expected database failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database write failed', $e->getMessage());
        }
        $this->assertSame(0, MapWaypointImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
