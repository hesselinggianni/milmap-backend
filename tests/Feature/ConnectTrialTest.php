<?php

namespace Tests\Feature;

use App\Models\ConnectOrganization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConnectTrialTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'sam@example.test', array $extra = []): User
    {
        return User::create(array_merge(['first_name' => 'Sam', 'last_name' => 'Jansen', 'email' => $email, 'password' => bcrypt('TestPassword1!'),
            'email_verified_at' => now(), 'trial_ends_at' => now()->subDay()], $extra));
    }

    public function test_logged_in_user_starts_a_30_day_trial_and_can_add_users_and_devices_right_away(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->actingAs($user, 'sanctum');
        $this->assertSame([], $this->getJson('/api/v1/connect/organizations')->assertOk()->json('organizations'));

        $response = $this->postJson('/api/v1/connect/trial', ['name' => '  Basisschool De Brug '])->assertCreated();
        $organization = ConnectOrganization::findOrFail($response->json('organization.id'));
        $this->assertSame('Basisschool De Brug', $organization->name);
        $this->assertTrue($organization->licensed());
        $license = $organization->licenses()->sole();
        $this->assertTrue($license->is_trial);
        $this->assertSame('Proeflicentie', $license->name);
        $this->assertSame(25, $license->seat_count);
        $this->assertEquals(30, (int) round(now()->diffInDays($license->ends_at, false)));
        $this->assertDatabaseHas('connect_members', ['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        // De gebruiker is nu beheerder: de organisatie verschijnt in Connect en gebruikers/apparaten toevoegen werkt direct.
        $this->assertCount(1, $this->getJson('/api/v1/connect/organizations')->assertOk()->json('organizations'));
        $this->getJson("/api/v1/connect/organizations/{$organization->id}")->assertOk()->assertJsonPath('licensed', true)->assertJsonPath('role', 'owner');
        $this->postJson("/api/v1/connect/organizations/{$organization->id}/members", ['members' => [['first_name' => 'Lisa', 'last_name' => 'Bakker', 'email' => 'lisa@example.test']]])->assertCreated();
        $this->postJson("/api/v1/connect/organizations/{$organization->id}/allowed-devices", ['devices' => [['name' => 'iPad klas 1', 'udid' => '00008103-001A2B3C4D5E6F78']]])->assertSuccessful();
    }

    public function test_a_second_trial_for_the_same_account_is_refused(): void
    {
        $this->actingAs($this->user(), 'sanctum');
        $this->postJson('/api/v1/connect/trial', ['name' => 'Eerste'])->assertCreated();
        $this->postJson('/api/v1/connect/trial', ['name' => 'Tweede'])->assertUnprocessable();
        $this->assertDatabaseCount('connect_organizations', 1);
    }

    public function test_an_organization_name_is_required_and_guests_cannot_start_a_trial(): void
    {
        $this->postJson('/api/v1/connect/trial', ['name' => 'Gast'])->assertUnauthorized();
        $this->actingAs($this->user(), 'sanctum');
        $this->postJson('/api/v1/connect/trial', ['name' => '  '])->assertUnprocessable();
        $this->assertDatabaseCount('connect_organizations', 0);
    }

    public function test_an_unverified_account_past_its_grace_period_cannot_start_a_trial(): void
    {
        $user = $this->user('late@example.test', ['email_verified_at' => null]);
        $user->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->assertTrue($user->fresh()->isEmailVerificationWalled());
        $this->actingAs($user->fresh(), 'sanctum');
        $this->postJson('/api/v1/connect/trial', ['name' => 'Te laat'])->assertForbidden();
        $this->assertDatabaseCount('connect_organizations', 0);
    }
}
