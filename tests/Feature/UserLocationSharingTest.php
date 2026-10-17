<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Live locatie delen: de "onbekend"-waarden van iOS/Android (heading/speed = -1)
// mogen een positie niet met een 422 weigeren, en stoppen is idempotent.
class UserLocationSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['first_name' => 'Test', 'last_name' => 'User', 'email' => Str::uuid().'@example.org', 'password' => bcrypt('Password123!'), 'email_verified_at' => now()]);
        // Live delen is een premiumfunctie; die poort is hier niet het onderwerp.
        $this->user->forceFill(['is_admin' => true])->save();
        $this->map = Map::create(['owner_id' => $this->user->id, 'title' => 'Live', 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    private function ping(array $extra)
    {
        return $this->postJson('/api/v1/maps/'.$this->map->id.'/user-locations/update', ['latitude' => 51.5, 'longitude' => 5.3] + $extra);
    }

    public function test_unknown_heading_and_speed_are_stored_as_null(): void
    {
        $this->ping(['heading' => -1, 'speed' => -1, 'accuracy' => 12])
            ->assertOk()
            ->assertJsonPath('location.heading', null)
            ->assertJsonPath('location.speed', null);

        $this->assertDatabaseHas('user_locations', ['map_id' => $this->map->id, 'user_id' => $this->user->id, 'heading' => null, 'speed' => null]);
    }

    public function test_heading_of_360_or_more_wraps_and_garbage_is_still_rejected(): void
    {
        $this->ping(['heading' => 450, 'speed' => 1.5])->assertOk()->assertJsonPath('location.heading', 90);

        $this->travel(2)->seconds();
        $this->ping(['heading' => 'noord'])->assertUnprocessable()->assertJsonValidationErrors('heading');
    }

    public function test_mission_track_batch_accepts_unknown_heading_and_speed(): void
    {
        $mission = Mission::create(['owner_id' => $this->user->id, 'name' => 'Oefening']);
        $point = fn (array $extra) => ['lat' => 51.5, 'lng' => 5.3, 'recorded_at' => now()->toIso8601String()] + $extra;

        $this->postJson('/api/v1/missions/'.$mission->id.'/tracks', ['points' => [
            $point(['heading' => -1, 'speed' => -1]),
            $point(['heading' => 90, 'speed' => 1.2]),
        ]])->assertCreated()->assertJsonPath('stored', 2);

        $this->assertDatabaseHas('mission_tracks', ['mission_id' => $mission->id, 'heading' => null, 'speed' => null]);
    }

    public function test_stop_sharing_twice_is_not_an_error(): void
    {
        $this->ping([])->assertOk();
        $url = '/api/v1/maps/'.$this->map->id.'/user-locations/stop-sharing';

        $this->deleteJson($url)->assertOk()->assertJsonPath('success', true);
        $this->deleteJson($url)->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('user_locations', ['map_id' => $this->map->id, 'user_id' => $this->user->id]);
    }
}
