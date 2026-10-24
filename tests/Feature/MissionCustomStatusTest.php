<?php
namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Een missie kan een eigen status krijgen (de app bewaart de definitie, de missie alleen de sleutel).
 * Voorheen gaf dat een 422 (validation.in) en, na de validatie, een databasefout door de ENUM-kolom.
 */
class MissionCustomStatusTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['first_name' => 'Eigenaar', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDay()]);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    public function test_a_custom_status_can_be_set_and_is_kept(): void
    {
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Oefening', 'status' => 'planning']);

        $this->api($owner)->putJson('/api/v1/missions/'.$mission->id, ['status' => 'in-voorbereiding'])->assertOk();
        $this->assertSame('in-voorbereiding', $mission->fresh()->status);

        // Terug naar een ingebouwde status en weer naar een eigen status.
        $this->api($owner)->putJson('/api/v1/missions/'.$mission->id, ['status' => 'complete'])->assertOk();
        $this->assertSame('complete', $mission->fresh()->status);
        $this->api($owner)->putJson('/api/v1/missions/'.$mission->id, ['status' => 'Wacht_op_akkoord2'])->assertOk();
        $this->assertSame('Wacht_op_akkoord2', $mission->fresh()->status);
    }

    public function test_a_custom_status_can_be_set_when_creating_a_mission(): void
    {
        $owner = $this->owner();
        $this->api($owner)->postJson('/api/v1/missions', ['name' => 'Nieuw', 'status' => 'eigen-status'])->assertSuccessful();
        $this->assertSame('eigen-status', Mission::where('owner_id', $owner->id)->where('name', 'Nieuw')->firstOrFail()->status);
    }

    public function test_statuses_that_are_not_a_plain_key_are_still_refused(): void
    {
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Oefening', 'status' => 'planning']);
        foreach (['met spatie', '<script>', '-begint-met-streepje', str_repeat('a', 65)] as $bad) {
            $this->api($owner)->putJson('/api/v1/missions/'.$mission->id, ['status' => $bad])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame('planning', $mission->fresh()->status);
    }
}
