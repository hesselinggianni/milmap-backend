<?php

namespace Tests\Feature;

use App\Models\{Mission, MissionTask, Team, TeamMember, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionTaskTeamProgressTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['first_name' => 'Test', 'last_name' => 'User', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    private function setupTeams(): array
    {
        $owner = $this->user();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Mission', 'status' => 'active']);
        $teams = [];
        foreach (['Alpha', 'Bravo'] as $name) {
            $team = Team::create(['owner_id' => $owner->id, 'name' => $name]);
            DB::table('mission_teams')->insert(['id' => (string) Str::uuid(), 'mission_id' => $mission->id, 'team_id' => $team->id, 'created_at' => now(), 'updated_at' => now()]);
            $teams[] = $team;
        }
        return [$owner, $mission, ...$teams];
    }

    private function createTask(User $owner, Mission $mission): string
    {
        return $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", ['title' => 'Presentatie maken', 'all_teams' => true])
            ->assertCreated()->assertJsonCount(2, 'task.team_progress')->assertJsonPath('task.all_teams', true)->json('task.id');
    }

    public function test_each_team_has_its_own_status_and_comments_and_overall_completion(): void
    {
        [$owner, $mission, $alpha, $bravo] = $this->setupTeams();
        $id = $this->createTask($owner, $mission);
        $member = $this->user();
        TeamMember::create(['team_id' => $alpha->id, 'user_id' => $member->id, 'email' => $member->email, 'added_by' => $owner->id, 'status' => 'active', 'role' => 'member']);
        $url = "/api/v1/missions/{$mission->id}/tasks/{$id}/teams/";
        $res = $this->api($member)->putJson($url.$alpha->id, ['status' => 'done', 'comment' => 'Presentatie gereed'])->assertOk()->assertJsonPath('task.status', 'doing');
        $progress = collect($res->json('task.team_progress'))->keyBy('team_id');
        $this->assertSame('done', $progress[$alpha->id]['status']);
        $this->assertSame('Presentatie gereed', $progress[$alpha->id]['comments'][0]['body']);
        $this->assertSame('todo', $progress[$bravo->id]['status']);
        $this->assertSame([], $progress[$bravo->id]['comments']);
        $this->api($member)->putJson($url.$bravo->id, ['status' => 'done'])->assertForbidden();
        $this->api($member)->putJson($url.$bravo->id, ['comment' => 'Wrong team'])->assertForbidden();
        $this->api($owner)->putJson($url.$bravo->id, ['status' => 'done'])->assertOk()->assertJsonPath('task.status', 'done');
        $this->api($owner)->putJson($url.$alpha->id, ['status' => 'todo'])->assertOk()->assertJsonPath('task.status', 'doing');
        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}", ['status' => 'done'])->assertUnprocessable();
    }

    public function test_all_teams_is_a_snapshot_and_edits_preserve_progress(): void
    {
        [$owner, $mission, $alpha] = $this->setupTeams();
        $id = $this->createTask($owner, $mission);
        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}/teams/{$alpha->id}", ['status' => 'done', 'comment' => 'Ready'])->assertOk();
        $extra = Team::create(['owner_id' => $owner->id, 'name' => 'Charlie']);
        DB::table('mission_teams')->insert(['id' => (string) Str::uuid(), 'mission_id' => $mission->id, 'team_id' => $extra->id]);
        $res = $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}", ['title' => 'New title', 'all_teams' => true, 'assigned_team_id' => null])
            ->assertOk()->assertJsonCount(2, 'task.team_progress');
        $progress = collect($res->json('task.team_progress'))->keyBy('team_id');
        $this->assertSame('done', $progress[$alpha->id]['status']);
        $this->assertCount(1, $progress[$alpha->id]['comments']);
    }

    public function test_team_assignment_and_switch_to_individual(): void
    {
        [$owner, $mission, $alpha] = $this->setupTeams();
        $id = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", ['title' => 'One team', 'assigned_team_id' => $alpha->id])
            ->assertCreated()->assertJsonCount(1, 'task.team_progress')->json('task.id');
        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}", ['assigned_team_id' => null, 'all_teams' => false, 'assignee_user_ids' => [$owner->id]])
            ->assertOk()->assertJsonCount(0, 'task.team_progress')->assertJsonCount(1, 'task.assignees')->assertJsonPath('task.all_teams', false);
    }

    public function test_unlinked_team_and_empty_all_teams_are_rejected_without_partial_writes(): void
    {
        [$owner, $mission] = $this->setupTeams();
        $other = Team::create(['owner_id' => $owner->id, 'name' => 'Unlinked']);
        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/tasks", ['title' => 'Invalid', 'assigned_team_id' => $other->id])->assertUnprocessable();
        $this->assertSame(0, MissionTask::count());
        $empty = Mission::create(['owner_id' => $owner->id, 'name' => 'Empty']);
        $this->api($owner)->postJson("/api/v1/missions/{$empty->id}/tasks", ['title' => 'Invalid', 'all_teams' => true])->assertUnprocessable();
        $this->assertSame(0, MissionTask::count());
    }

    public function test_assignee_picker_returns_members_grouped_by_mission_team(): void
    {
        [$owner, $mission, $alpha] = $this->setupTeams();
        $member = $this->user();
        TeamMember::create(['team_id' => $alpha->id, 'user_id' => $member->id, 'email' => $member->email, 'added_by' => $owner->id, 'status' => 'active', 'role' => 'member']);
        $res = $this->api($owner)->getJson("/api/v1/missions/{$mission->id}/tasks/assignees")->assertOk()->assertJsonCount(2, 'teams');
        $team = collect($res->json('teams'))->firstWhere('id', $alpha->id);
        $this->assertContains($member->id, array_column($team['users'], 'id'));
    }
    public function test_my_tasks_uses_own_team_status_and_pending_members_cannot_update(): void
    {
        [$owner, $mission, $alpha] = $this->setupTeams();
        $id = $this->createTask($owner, $mission);
        $member = $this->user();
        $membership = TeamMember::create(['team_id' => $alpha->id, 'user_id' => $member->id, 'email' => $member->email, 'added_by' => $owner->id, 'status' => 'active', 'role' => 'member']);
        $this->api($member)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}/teams/{$alpha->id}", ['status' => 'done'])->assertOk()->assertJsonPath('task.status', 'doing');
        $this->api($member)->getJson('/api/v1/me/tasks')->assertOk()->assertJsonPath('tasks.0.status', 'done');
        $membership->update(['status' => 'pending']);
        $this->api($member)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}/teams/{$alpha->id}", ['status' => 'todo'])->assertForbidden();
        $this->api($member)->getJson('/api/v1/me/tasks')->assertOk()->assertJsonCount(0, 'tasks');
    }

    public function test_progress_endpoint_requires_task_to_belong_to_mission_and_valid_payload(): void
    {
        [$owner, $mission, $alpha] = $this->setupTeams();
        $id = $this->createTask($owner, $mission);
        $otherMission = Mission::create(['owner_id' => $owner->id, 'name' => 'Other']);
        $this->api($owner)->putJson("/api/v1/missions/{$otherMission->id}/tasks/{$id}/teams/{$alpha->id}", ['status' => 'done'])->assertNotFound();
        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}/teams/{$alpha->id}", ['status' => 'invalid'])->assertUnprocessable();
        $this->api($owner)->putJson("/api/v1/missions/{$mission->id}/tasks/{$id}/teams/{$alpha->id}", ['comment' => '   '])->assertUnprocessable();
    }

}
