<?php

namespace Tests\Feature;

use App\Models\ConnectMember;
use App\Models\ConnectOrganization;
use App\Models\PlanPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Na de 7 dagen: 1 kaart gratis; tracks/weer/routekaarten/live/shares/bergpakket betaald. */
class PremiumGatesAfterTrialTest extends TestCase
{
    use RefreshDatabase;

    private const GATED = ['tracks', 'route_maps', 'shares', 'mountain_pack', 'weather', 'live_location'];

    private function user(string $email, $trialEnds): User
    {
        return User::create([
            'first_name' => 'T', 'last_name' => 'U', 'email' => $email,
            'password' => bcrypt('TestPassword1!'), 'email_verified_at' => now(),
            'trial_ends_at' => $trialEnds,
        ]);
    }

    private function connect(User $u, $endsAt): void
    {
        $org = ConnectOrganization::create([
            'name' => 'School', 'seat_limit' => 5, 'restrict_to_allowed_devices' => false,
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => $endsAt,
        ]);
        ConnectMember::create([
            'organization_id' => $org->id, 'user_id' => $u->id, 'email' => $u->email,
            'first_name' => 'T', 'last_name' => 'U', 'role' => 'member', 'status' => 'active',
        ]);
    }

    private function assertPaywalled(User $u): void
    {
        $this->actingAs($u, 'sanctum');
        $this->postJson('/api/v1/activities', [])->assertStatus(402)->assertJsonPath('feature', 'tracks');
        $this->postJson('/api/v1/routemaps', [])->assertStatus(402)->assertJsonPath('feature', 'route_maps');
        $this->postJson('/api/v1/maps/1/shares', [])->assertStatus(402)->assertJsonPath('feature', 'shares');
        $this->postJson('/api/v1/maps/1/user-locations/update', [])->assertStatus(402)->assertJsonPath('feature', 'live_location');
        $this->postJson('/api/v1/weather-alerts/access')->assertStatus(402);
        foreach (self::GATED as $f) {
            $this->assertFalse($u->fresh()->hasFeature($f, 'update'), "$f hoort dicht te zijn");
        }
    }

    private function assertOpen(User $u): void
    {
        $this->actingAs($u, 'sanctum');
        foreach (['/activities', '/routemaps', '/maps/1/shares', '/maps/1/user-locations/update'] as $path) {
            $this->assertNotSame(402, $this->postJson('/api/v1' . $path, [])->status(), "$path onverwacht 402");
        }
        foreach (self::GATED as $f) {
            $this->assertTrue($u->fresh()->hasFeature($f, 'update'), "$f hoort open te zijn");
        }
        $this->assertNull($u->fresh()->premiumState()['map_limit']);
    }

    public function test_free_limit_is_one_map(): void
    {
        $this->assertSame(1, User::FREE_MAP_LIMIT);
    }

    public function test_expired_trial_is_paywalled_and_one_map_only(): void
    {
        $u = $this->user('free@test.nl', now()->subDay());
        $this->assertPaywalled($u);
        $this->assertSame(1, $u->premiumState()['map_limit']);
        $this->postJson('/api/v1/maps', ['title' => 'Eerste'])->assertSuccessful();
        $this->postJson('/api/v1/maps', ['title' => 'Tweede'])->assertStatus(402)->assertJsonPath('feature', 'unlimited_maps');
        // lezen blijft gratis
        $this->getJson('/api/v1/activities')->assertOk();
        $this->getJson('/api/v1/routemaps')->assertOk();
    }

    public function test_running_trial_has_everything(): void
    {
        $u = $this->user('trial@test.nl', now()->addDays(3));
        $this->assertOpen($u);
        $this->postJson('/api/v1/maps', ['title' => 'A'])->assertSuccessful();
        $this->postJson('/api/v1/maps', ['title' => 'B'])->assertSuccessful();
    }

    public function test_licensed_connect_member_has_everything_after_trial(): void
    {
        $u = $this->user('connect@test.nl', now()->subDay());
        $this->connect($u, now()->addYear());
        $this->assertOpen($u);
    }

    public function test_expired_connect_license_falls_back_to_paywall(): void
    {
        $u = $this->user('oldconnect@test.nl', now()->subDay());
        $this->connect($u, now()->subSecond());
        // Beheerd (Connect-)account zonder geldige licentie: de organisatie-policy
        // weigert de actie (403), en de features staan in elk geval dicht.
        $this->actingAs($u, 'sanctum');
        $this->postJson('/api/v1/activities', [])->assertForbidden()->assertJsonPath('code', 'connect_policy_denied');
        foreach (self::GATED as $f) {
            $this->assertFalse($u->fresh()->hasFeature($f, 'update'), "$f hoort dicht te zijn");
        }
    }

    public function test_plan_matrix_seeded_starter_closed_paid_plans_open(): void
    {
        foreach (['tracks', 'route_maps', 'shares', 'mountain_pack'] as $m) {
            $this->assertContains($m, PlanPermission::MODULES);
            $this->assertFalse((bool) PlanPermission::where(['plan' => 'starter', 'module' => $m])->value('can_update'));
            foreach (['pro', 'team', 'lifetime'] as $plan) {
                $this->assertTrue((bool) PlanPermission::where(['plan' => $plan, 'module' => $m])->value('can_update'), "$plan/$m");
            }
        }
    }
}
