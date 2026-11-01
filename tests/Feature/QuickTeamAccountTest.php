<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickTeamAccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['first_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'settings' => ['theme' => 'dark']]);
    }
    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }
    public function test_roster_is_saved_to_account_with_all_member_types_and_settings_preserved(): void
    {
        $owner = $this->user();
        $this->api($owner)->postJson('/api/v1/me/quick-teams', [
            'name' => 'Alpha', 'members' => [['name' => 'User', 'user_id' => $owner->id], ['name' => 'Manual'], ['name' => 'Radio', 'device_id' => 123]],
        ])->assertCreated()->assertJsonCount(3, 'team.members');
        $this->api($owner)->getJson('/api/v1/me/quick-teams')->assertOk()->assertJsonCount(1, 'teams')->assertJsonCount(3, 'teams.0.members');
        $this->assertSame('dark', $owner->fresh()->settings['theme']);
        $this->assertDatabaseCount('team_members', 0);
    }
    public function test_account_isolation_and_same_name_merges_without_duplicates(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $id = $this->api($owner)->postJson('/api/v1/me/quick-teams', ['name' => 'Alpha', 'members' => [['name' => 'Jan']]])->assertCreated()->json('team.id');
        $this->api($owner)->postJson('/api/v1/me/quick-teams', ['name' => 'alpha', 'members' => [['name' => 'jan'], ['name' => 'Piet']]])
            ->assertCreated()->assertJsonPath('team.id', $id)->assertJsonCount(2, 'team.members');
        $this->api($owner)->getJson('/api/v1/me/quick-teams')->assertOk()->assertJsonCount(1, 'teams');
        $this->api($other)->getJson('/api/v1/me/quick-teams')->assertOk()->assertJsonCount(0, 'teams');
    }
    public function test_invalid_roster_is_not_saved(): void
    {
        $owner = $this->user();
        $this->api($owner)->postJson('/api/v1/me/quick-teams', ['name' => ' ', 'members' => [['name' => 'Jan']]])->assertUnprocessable();
        $this->api($owner)->postJson('/api/v1/me/quick-teams', ['name' => 'Alpha', 'members' => []])->assertUnprocessable();
        $this->api($owner)->postJson('/api/v1/me/quick-teams', ['name' => 'Alpha', 'members' => [['name' => ' ']]])->assertUnprocessable();
        $this->api($owner)->getJson('/api/v1/me/quick-teams')->assertOk()->assertJsonCount(0, 'teams');
    }
}
