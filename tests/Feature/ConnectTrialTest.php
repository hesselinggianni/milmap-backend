<?php

namespace Tests\Feature;

use App\Models\ConnectOrganization;
use App\Models\ConnectOrder;
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

    private function startTrial(?User $user = null): ConnectOrganization
    {
        $this->actingAs($user ?? $this->user(), 'sanctum');
        return ConnectOrganization::findOrFail($this->postJson('/api/v1/connect/trial', ['name' => 'Basisschool De Brug'])->assertCreated()->json('organization.id'));
    }

    private function orderUrl(ConnectOrganization $organization): string { return "/api/v1/connect/organizations/{$organization->id}/billing/orders"; }

    public function test_the_dashboard_data_tells_until_when_the_trial_runs(): void
    {
        $organization = $this->startTrial();
        $trial = $this->getJson("/api/v1/connect/organizations/{$organization->id}")->assertOk()->json('trial');
        $this->assertTrue($trial['active']);
        $this->assertFalse($trial['wall']);
        $this->assertSame(30, $trial['days_left']);
        $this->assertSame(25, $trial['seats']);
        $this->assertSame($organization->licenses()->sole()->ends_at->toIso8601String(), \Illuminate\Support\Carbon::parse($trial['ends_at'])->toIso8601String());
    }

    public function test_ordering_during_the_trial_costs_nothing_and_adds_seats_to_the_trial_license(): void
    {
        $organization = $this->startTrial();
        $body = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'seats' => 5, 'accepted' => true];
        // Geen Stripe nodig (niet geconfigureerd in de test): een proefbestelling raakt de betaalprovider niet.
        $order = $this->postJson($this->orderUrl($organization), $body)->assertCreated()->assertJsonPath('trial', true)->assertJsonPath('order.status', 'trial')->assertJsonPath('order.seats', 5)->assertJsonPath('order.total_incl_cents', 0);
        $this->assertSame(30, $organization->fresh()->seat_limit);
        $this->assertSame(30, $organization->licenses()->sole()->seat_count);
        $this->assertTrue($organization->licenses()->sole()->is_trial);
        // Opnieuw versturen (dubbelklik, herladen) telt niet dubbel.
        $this->postJson($this->orderUrl($organization), $body)->assertCreated()->assertJsonPath('order.id', $order->json('order.id'));
        $this->assertSame(30, $organization->fresh()->seat_limit);
        $this->assertDatabaseCount('connect_orders', 1);
        $this->postJson($this->orderUrl($organization), ['seats' => 6] + $body)->assertStatus(409);
        $this->getJson("/api/v1/connect/organizations/{$organization->id}/billing/orders")->assertOk()->assertJsonPath('data.0.status', 'trial');
    }

    public function test_the_trial_order_needs_consent_and_stays_below_the_trial_cap(): void
    {
        $organization = $this->startTrial();
        $uuid = fn () => (string) \Illuminate\Support\Str::uuid();
        $this->postJson($this->orderUrl($organization), ['request_id' => $uuid(), 'seats' => 5])->assertUnprocessable();
        $this->postJson($this->orderUrl($organization), ['request_id' => $uuid(), 'seats' => \App\Services\ConnectTrial::MAX_SEATS, 'accepted' => true])->assertUnprocessable();
        $this->assertSame(25, $organization->fresh()->seat_limit);
        $this->postJson($this->orderUrl($organization), ['request_id' => $uuid(), 'seats' => \App\Services\ConnectTrial::MAX_SEATS - 25, 'accepted' => true])->assertCreated();
        $this->assertSame(\App\Services\ConnectTrial::MAX_SEATS, $organization->fresh()->seat_limit);
    }

    public function test_more_users_than_free_seats_are_added_straight_away_after_a_free_trial_order(): void
    {
        $organization = $this->startTrial();
        $devices = collect(range(1, 30))->map(fn ($n) => ['name' => 'iPad '.$n, 'udid' => sprintf('00008103-%016X', $n)])->all();
        $pending = $this->postJson("/api/v1/connect/organizations/{$organization->id}/pending-additions", ['kind' => 'devices', 'devices' => $devices])->assertCreated()->json('pending_addition');
        $this->assertSame(6, $pending['seats_needed']);
        $this->postJson($this->orderUrl($organization), ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'seats' => 6, 'accepted' => true, 'pending_addition_id' => $pending['id']])->assertCreated();
        $this->assertDatabaseCount('connect_allowed_devices', 30);
        $this->assertSame('completed', $this->getJson("/api/v1/connect/organizations/{$organization->id}/pending-additions/{$pending['id']}")->assertOk()->json('pending_addition.status'));
    }

    public function test_the_checkout_catalog_works_during_the_trial_without_a_seat_price(): void
    {
        $organization = $this->startTrial();
        $catalog = $this->getJson("/api/v1/connect/organizations/{$organization->id}/billing/catalog")->assertOk();
        $catalog->assertJsonPath('pricing', null)->assertJsonPath('trial.active', true)->assertJsonPath('trial.days_left', 30);
    }

    public function test_after_the_trial_the_wall_shows_and_a_paid_license_removes_it(): void
    {
        $organization = $this->startTrial();
        $organization->licenses()->update(['starts_at' => now()->subDays(31), 'ends_at' => now()->subDay()]);
        $show = $this->getJson("/api/v1/connect/organizations/{$organization->id}")->assertOk();
        $show->assertJsonPath('licensed', false)->assertJsonPath('trial.wall', true)->assertJsonPath('trial.active', false)->assertJsonPath('trial.seats', 0);
        // Een trial-bestelling kan niet meer (geen actieve proefperiode).
        $this->postJson($this->orderUrl($organization), ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'seats' => 5, 'accepted' => true])->assertUnprocessable();
        $organization->licenses()->create(['name' => 'Connect · 10 seats · 12 maanden', 'seat_count' => 10, 'currency' => 'EUR', 'starts_at' => now(), 'ends_at' => now()->addYear(), 'source_reference' => 'stripe-invoice:in_x']);
        $this->getJson("/api/v1/connect/organizations/{$organization->id}")->assertOk()->assertJsonPath('trial', null)->assertJsonPath('licensed', true);
    }

    public function test_an_organization_that_never_had_a_trial_gets_no_wall(): void
    {
        $organization = $this->startTrial();
        $organization->licenses()->delete();
        $organization->licenses()->create(['name' => 'Enterprise', 'seat_count' => 10, 'currency' => 'EUR', 'starts_at' => now()->subYear(), 'ends_at' => now()->subDay()]);
        $this->getJson("/api/v1/connect/organizations/{$organization->id}")->assertOk()->assertJsonPath('trial', null)->assertJsonPath('licensed', false);
    }
}
