<?php
namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionBriefing;
use App\Models\MissionCollaborator;
use App\Models\MissionRadioChannel;
use App\Models\MissionRisk;
use App\Models\MissionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Een missie kopiëren: inhoud (O-group, briefing, netten, risico's, taken) gaat mee,
 * mensen, tracks en vergrendeling niet; de kopie is van degene die kopieert.
 */
class MissionDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $first = 'Eigenaar', bool $premium = true): User
    {
        $user = User::create(['first_name' => $first, 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
        if ($premium) {
            $user->trial_ends_at = now()->addDay(); // gratis accounts zitten al aan hun missielimiet met het origineel
            $user->save();
        }

        return $user;
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    private function missionWithContent(User $owner): Mission
    {
        $mission = Mission::create([
            'owner_id' => $owner->id, 'name' => 'Oefening Noord', 'status' => 'active', 'locked' => true,
            'description' => 'Omschrijving', 'time' => '0600',
            'ogroup' => ['situation' => ['enemy' => 'Vijand'], 'participants' => [['name' => 'Jan']]],
        ]);
        MissionBriefing::create(['mission_id' => $mission->id, 'enemy_forces' => 'Geheim', 'timeline' => [['t' => '0600']]]);
        MissionRadioChannel::create(['mission_id' => $mission->id, 'net_name' => 'Net 1', 'frequency' => '123.4', 'callsign' => 'Alpha', 'is_primary' => true, 'sort_order' => 0]);
        MissionRisk::create(['mission_id' => $mission->id, 'description' => 'Gladheid', 'likelihood' => 3, 'impact' => 2, 'mitigation' => 'Strooien', 'sort_order' => 0]);
        $parent = MissionTask::create(['mission_id' => $mission->id, 'title' => 'Verkennen', 'status' => 'done', 'order_index' => 1, 'assignee_user_id' => $owner->id, 'assignee_participants' => [['name' => 'Piet']], 'created_by' => $owner->id]);
        MissionTask::create(['mission_id' => $mission->id, 'parent_id' => $parent->id, 'title' => 'Route lopen', 'status' => 'doing', 'order_index' => 1, 'created_by' => $owner->id]);

        return $mission;
    }

    public function test_owner_copies_content_into_a_fresh_planning_mission(): void
    {
        $owner   = $this->user();
        $mission = $this->missionWithContent($owner);
        $other   = $this->user('Collega');
        MissionCollaborator::create(['mission_id' => $mission->id, 'user_id' => $other->id, 'added_by' => $owner->id, 'role' => 'viewer', 'status' => 'accepted']);

        $res = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/duplicate", ['name' => 'Oefening Zuid'])->assertCreated();

        $res->assertJsonPath('data.name', 'Oefening Zuid')
            ->assertJsonPath('data.status', 'planning')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.ogroup.situation.enemy', 'Vijand')
            ->assertJsonPath('data.ogroup.participants.0.name', 'Jan')
            ->assertJsonCount(0, 'data.collaborators');

        $copy = Mission::findOrFail($res->json('data.id'));
        $this->assertNotSame($mission->id, $copy->id);
        $this->assertFalse((bool) $copy->locked);
        $this->assertSame('Geheim', $copy->briefing->enemy_forces);
        $this->assertSame([['t' => '0600']], $copy->briefing->timeline);
        $this->assertSame('Alpha', $copy->radioChannels->first()->callsign);
        $this->assertSame('Strooien', $copy->risks->first()->mitigation);

        // Origineel blijft onaangeroerd.
        $this->assertSame(1, MissionRisk::where('mission_id', $mission->id)->count());
        $this->assertTrue((bool) $mission->fresh()->locked);
    }

    public function test_tasks_come_along_open_with_subtasks_and_without_other_assignees(): void
    {
        $owner   = $this->user();
        $mission = $this->missionWithContent($owner);

        $copyId = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/duplicate")->assertCreated()->json('data.id');

        $tasks = MissionTask::where('mission_id', $copyId)->get();
        $this->assertCount(2, $tasks);
        $this->assertSame(['todo'], $tasks->pluck('status')->unique()->values()->all());
        $parent = $tasks->firstWhere('title', 'Verkennen');
        $child  = $tasks->firstWhere('title', 'Route lopen');
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame([['name' => 'Piet']], $parent->assignee_participants);
    }

    public function test_default_name_gets_a_copy_suffix_and_tasks_can_be_left_out(): void
    {
        $owner   = $this->user();
        $mission = $this->missionWithContent($owner);

        $res = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/duplicate", ['include_tasks' => false])->assertCreated();

        $res->assertJsonPath('data.name', 'Oefening Noord (kopie)');
        $this->assertSame(0, MissionTask::where('mission_id', $res->json('data.id'))->count());
    }

    public function test_editor_copy_is_owned_by_the_editor_and_viewers_and_strangers_are_refused(): void
    {
        $owner   = $this->user();
        $mission = $this->missionWithContent($owner);
        $editor  = $this->user('Editor');
        $viewer  = $this->user('Kijker');
        $vreemd  = $this->user('Vreemd');
        MissionCollaborator::create(['mission_id' => $mission->id, 'user_id' => $editor->id, 'added_by' => $owner->id, 'role' => 'editor', 'status' => 'accepted']);
        MissionCollaborator::create(['mission_id' => $mission->id, 'user_id' => $viewer->id, 'added_by' => $owner->id, 'role' => 'viewer', 'status' => 'accepted']);

        $copyId = $this->api($editor)->postJson("/api/v1/missions/{$mission->id}/duplicate")->assertCreated()->json('data.id');
        $this->assertSame($editor->id, Mission::findOrFail($copyId)->owner_id);

        $this->api($viewer)->postJson("/api/v1/missions/{$mission->id}/duplicate")->assertForbidden();
        $this->api($vreemd)->postJson("/api/v1/missions/{$mission->id}/duplicate")->assertForbidden();
    }

    public function test_free_account_at_its_mission_limit_gets_a_402(): void
    {
        $owner   = $this->user('Gratis', premium: false);
        $mission = $this->missionWithContent($owner);

        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/duplicate")
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required');
        $this->assertSame(1, Mission::where('owner_id', $owner->id)->count());
    }
}
