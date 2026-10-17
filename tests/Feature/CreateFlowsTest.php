<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateFlowsTest extends TestCase
{
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
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('avatar_path')->nullable();
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
            $t->uuid('id')->primary();
            $t->uuid('map_id');
            $t->integer('user_id');
            $t->integer('added_by')->nullable();
            $t->string('role');
            $t->string('status');
            $t->string('invitation_token')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('route_maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->uuid('map_id');
            $t->uuid('mission_id')->nullable();
            $t->string('owner_id');
            $t->string('title')->nullable();
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('color')->nullable();
            $t->float('declination')->nullable();
            $t->text('equipment')->nullable();
            $t->float('speed')->nullable();
            $t->string('ic')->nullable();
            $t->string('cs')->nullable();
            $t->json('locations')->nullable();
            $t->integer('pause_time')->default(0);
            $t->integer('total_time')->default(0);
            $t->float('total_distance')->default(0);
            $t->float('total_elevation')->default(0);
            $t->json('meta')->nullable();
            $t->json('bijlage')->nullable();
            $t->string('callsign')->nullable();
            $t->string('eenheid')->nullable();
            $t->integer('sterkte')->nullable();
            $t->string('kaartblad')->nullable();
            $t->string('vtv_vta')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('missions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->integer('owner_id');
            $t->uuid('linked_team_id')->nullable();
            $t->string('name');
            $t->string('mission_number')->nullable();
            $t->string('classification')->nullable();
            $t->text('description')->nullable();
            $t->string('status')->default('planning');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->json('ogroup')->nullable();
            $t->json('template_info')->nullable();
            $t->json('map')->nullable();
            $t->json('area')->nullable();
            $t->longText('logo')->nullable();
            $t->integer('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('locked')->default(false);
            $t->string('game_mode')->default('standard');
            $t->uuid('exercise_site_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('mission_id');
            $t->integer('user_id');
            $t->integer('added_by')->nullable();
            $t->string('role');
            $t->string('status');
            $t->string('invitation_token')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('teams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('owner_id')->nullable();
            $t->string('name');
            $t->string('color')->nullable();
            $t->timestamps();
        });

        Schema::create('team_members', function (Blueprint $t) {
            $t->id();
            $t->uuid('team_id');
            $t->integer('user_id');
            $t->timestamps();
        });

        DB::table('users')->insert([
            'id' => 10,
            'name' => 'Tester',
            'email' => 'tester@example.test',
            'is_admin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User();
        $user->id = 10;
        $user->is_admin = true;
        $this->actingAs($user, 'sanctum');
    }

    public function test_maps_create_route_stores_owner_title_settings_and_status(): void
    {
        $id = $this->postJson('/api/v1/maps', [
            'title' => 'Nieuwe kaart',
            'settings' => ['language' => 'nl'],
            'status' => 'active',
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('maps', [
            'id' => $id,
            'owner_id' => '10',
            'title' => 'Nieuwe kaart',
            'status' => 'active',
        ]);
    }

    public function test_routekaart_create_requires_and_persists_map_id(): void
    {
        $mapId = (string) Str::uuid();
        DB::table('maps')->insert([
            'id' => $mapId,
            'owner_id' => '10',
            'title' => 'Kaart',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/routemaps', ['title' => 'Zonder kaart'])->assertUnprocessable()->assertJsonValidationErrors('map_id');

        $routeMapId = $this->postJson('/api/v1/routemaps', [
            'map_id' => $mapId,
            'title' => 'Route Alpha',
            'color' => '#123456',
            'locations' => [['lat' => 52.1, 'lon' => 5.1]],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('route_maps', [
            'id' => $routeMapId,
            'map_id' => $mapId,
            'owner_id' => '10',
            'title' => 'Route Alpha',
        ]);
    }

    public function test_mission_create_persists_area_and_owner(): void
    {
        $area = ['minLon' => 4.0, 'minLat' => 51.0, 'maxLon' => 5.0, 'maxLat' => 52.0];

        $mission = $this->postJson('/api/v1/missions', [
            'name' => 'Missie Alpha',
            'area' => $area,
        ])->assertCreated()->json('data');

        $this->assertSame('Missie Alpha', $mission['name']);
        $this->assertEquals($area, $mission['area']);
        $this->assertDatabaseHas('missions', [
            'id' => $mission['id'],
            'owner_id' => 10,
            'name' => 'Missie Alpha',
        ]);
    }
}
