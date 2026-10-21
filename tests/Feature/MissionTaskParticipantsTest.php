<?php
namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Handmatig toegevoegde deelnemers (zonder account) als toewijzing van een taak:
 * momentopname [{name, role}], uniek op naam, wederzijds exclusief met het team,
 * en een PATCH zonder die sleutel laat ze ongemoeid.
 */
class MissionTaskParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['first_name' => 'Eigenaar', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    public function test_task_can_be_assigned_to_manual_participants_and_users_together(): void
    {
        $owner   = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);

        $res = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", [
            'title'                 => 'Verkennen',
            'assignee_user_ids'     => [$owner->id],
            'assignee_participants' => [
                ['name' => '  Jan  ', 'role' => 'Leider'],
                ['name' => 'jan'],            // dubbel (hoofdletterongevoelig) → weg
                ['name' => 'Piet'],
            ],
        ])->assertCreated();

        $res->assertJsonPath('task.participants.0.name', 'Jan')
            ->assertJsonPath('task.participants.0.role', 'Leider')
            ->assertJsonPath('task.participants.1.name', 'Piet')
            ->assertJsonCount(2, 'task.participants')
            ->assertJsonCount(1, 'task.assignees');
    }

    public function test_patch_without_participants_key_keeps_them_and_empty_list_clears_them(): void
    {
        $owner   = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $id = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", [
            'title' => 'T', 'assignee_participants' => [['name' => 'Jan']],
        ])->assertCreated()->json('task.id');

        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}", ['status' => 'doing'])
            ->assertOk()->assertJsonCount(1, 'task.participants');

        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}", ['assignee_participants' => []])
            ->assertOk()->assertJsonCount(0, 'task.participants');
    }

    public function test_assigning_a_team_clears_manual_participants(): void
    {
        $owner   = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $task = MissionTask::create(['mission_id' => $mission->id, 'title' => 'T', 'status' => 'todo', 'order_index' => 1, 'created_by' => $owner->id, 'assignee_participants' => [['name' => 'Jan', 'role' => null]]]);

        $teamId = (string) Str::uuid();
        \DB::table('teams')->insert(array_merge(['id' => $teamId, 'name' => 'Alpha', 'created_at' => now(), 'updated_at' => now()], $this->teamDefaults()));

        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$task->id}", [
            'assigned_team_id' => $teamId, 'assignee_participants' => [['name' => 'Jan']],
        ])->assertOk()->assertJsonCount(0, 'task.participants')->assertJsonPath('task.team.id', $teamId);
    }

    public function test_participant_name_is_required_and_list_is_capped(): void
    {
        $owner   = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", ['title' => 'T', 'assignee_participants' => [['role' => 'x']]])
            ->assertStatus(422);
        $many = array_map(fn ($i) => ['name' => "P{$i}"], range(1, 26));
        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", ['title' => 'T', 'assignee_participants' => $many])
            ->assertStatus(422);
    }

    /** Verplichte kolommen van `teams` zonder te raden: lees het schema. */
    private function teamDefaults(): array
    {
        $out = [];
        foreach (\Schema::getColumns('teams') as $c) {
            if (in_array($c['name'], ['id', 'name', 'created_at', 'updated_at'], true) || $c['nullable'] || $c['default'] !== null) {
                continue;
            }
            $out[$c['name']] = str_contains($c['type_name'] ?? $c['type'], 'int') ? 1 : 'x';
        }
        return $out;
    }
}
