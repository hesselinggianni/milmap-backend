<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Handmatig blokkeren vanuit admin (POST /admin/users/{id}/block|unblock):
 * tokens ingetrokken, inloggen geweigerd, 403 account_blocked op de API,
 * omkeerbaar via unblock. Beheerders zijn niet te blokkeren.
 */
class AdminBlockUserTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false): User
    {
        $user = User::create(['first_name' => 'Test', 'last_name' => $admin ? 'Beheerder' : 'Gebruiker', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
        $user->forceFill(['is_admin' => $admin])->save();
        return $user;
    }

    private function token(User $user, array $abilities = ['user']): string
    {
        return $user->createToken('test', $abilities, now()->addHour())->plainTextToken;
    }

    private function with(string $token): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($token);
    }

    public function test_block_revokes_sessions_refuses_login_and_unblock_restores_access(): void
    {
        $admin = $this->user(true);
        $user  = $this->user();
        $adminToken = $this->token($admin, ['admin', 'user']);
        $userToken  = $this->token($user);

        $this->with($userToken)->getJson('/api/v1/users/me')->assertOk();

        $this->with($adminToken)->postJson("/api/v1/admin/users/{$user->id}/block", ['reason' => 'Extreem API-verbruik'])
            ->assertOk()
            ->assertJsonPath('blocked.is_blocked', true)
            ->assertJsonPath('blocked.blocked_reason', 'Extreem API-verbruik')
            ->assertJsonPath('blocked.blocked_by_id', $admin->id);

        // Bestaande sessie is ingetrokken.
        $this->with($userToken)->getJson('/api/v1/users/me')->assertUnauthorized();

        // Inloggen met wachtwoord wordt geweigerd met een duidelijke melding.
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'test-password'])
            ->assertStatus(422)
            ->assertJsonFragment(['email' => [$user->fresh()->blockedLoginMessage()]]);

        // Een token dat tóch nog ontstaat (handoff/social) krijgt 403 account_blocked; uitloggen mag wel.
        $lateToken = $this->token($user->fresh());
        $this->with($lateToken)->getJson('/api/v1/users/me')->assertForbidden()->assertJsonPath('code', 'account_blocked');
        $this->with($lateToken)->getJson('/api/v1/maps')->assertForbidden()->assertJsonPath('code', 'account_blocked');
        $this->with($lateToken)->postJson('/api/v1/logout')->assertOk();

        // Admin ziet de status in profiel, gebruikerslijst en API-verbruik.
        $this->with($adminToken)->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk()->assertJsonPath('user.is_blocked', true)->assertJsonPath('user.blocked_reason', 'Extreem API-verbruik');
        $stats = $this->with($adminToken)->getJson('/api/v1/admin/stats')->assertOk()->json('users');
        $this->assertTrue(collect($stats)->firstWhere('id', $user->id)['is_blocked']);
        $this->assertFalse(collect($stats)->firstWhere('id', $admin->id)['is_blocked']);
        $this->with($adminToken)->getJson("/api/v1/admin/users/{$user->id}/api-usage")
            ->assertOk()->assertJsonPath('user.is_blocked', true);

        // Deblokkeren: inloggen werkt weer.
        $this->with($adminToken)->postJson("/api/v1/admin/users/{$user->id}/unblock")
            ->assertOk()->assertJsonPath('blocked.is_blocked', false)->assertJsonPath('blocked.blocked_reason', null);
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'test-password'])->assertOk();
        $this->assertNull($user->fresh()->blocked_at);
    }

    public function test_admins_cannot_be_blocked_and_only_admins_may_block(): void
    {
        $admin = $this->user(true);
        $other = $this->user(true);
        $user  = $this->user();
        $adminToken = $this->token($admin, ['admin', 'user']);

        $this->with($adminToken)->postJson("/api/v1/admin/users/{$other->id}/block")->assertStatus(422)->assertJsonPath('error', 'cannot_block_admin');
        $this->with($adminToken)->postJson("/api/v1/admin/users/{$admin->id}/block")->assertStatus(422);
        $this->with($adminToken)->postJson('/api/v1/admin/users/999999/block')->assertNotFound();

        // Gewone gebruiker (geen admin-token) mag dit niet.
        $this->with($this->token($user))->postJson("/api/v1/admin/users/{$admin->id}/block")->assertForbidden();
        $this->assertNull($other->fresh()->blocked_at);

        // Nogmaals blokkeren werkt alleen de reden bij, de datum blijft.
        $this->with($adminToken)->postJson("/api/v1/admin/users/{$user->id}/block", ['reason' => 'eerste'])->assertOk();
        $at = $user->fresh()->blocked_at;
        $this->travel(5)->minutes();
        $this->with($adminToken)->postJson("/api/v1/admin/users/{$user->id}/block", ['reason' => 'tweede'])->assertOk()->assertJsonPath('blocked.blocked_reason', 'tweede');
        $this->assertTrue($at->equalTo($user->fresh()->blocked_at));
    }
}
