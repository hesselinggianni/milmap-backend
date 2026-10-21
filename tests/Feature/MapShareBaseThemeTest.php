<?php
namespace Tests\Feature;

use App\Models\Map;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Standaardkaart van een deellink: settings.baseTheme (een van de basiskaarten van de app, bv. de NL-stafkaart
 * "top25"); leeg = de kaart opent met de eigen kaartinstelling. Een onbekende waarde wordt geweigerd.
 */
class MapShareBaseThemeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['first_name' => 'Eigenaar', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDays(3)]);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    public function test_default_map_is_stored_and_served_on_the_public_link(): void
    {
        $owner = $this->owner();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);

        $res = $this->api($owner)->postJson("/api/v1/maps/{$map->id}/shares", [
            'settings' => ['showTable' => true, 'baseTheme' => 'top25'],
        ])->assertCreated();
        $res->assertJsonPath('settings.baseTheme', 'top25');

        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/share/'.$res->json('token'))
            ->assertOk()->assertJsonPath('share.settings.baseTheme', 'top25');
    }

    public function test_every_base_map_of_the_app_is_allowed(): void
    {
        $owner = $this->owner();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);
        foreach (['outdoors-v12', 'streets-v11', 'satellite-v9', 'satellite-streets-v11', 'top25', 'norge-top25', 'de-topplus', 'usa-topo', 'pl-topo', 'lt-topo'] as $theme) {
            $this->api($owner)->postJson("/api/v1/maps/{$map->id}/shares", ['settings' => ['baseTheme' => $theme]])
                ->assertCreated()->assertJsonPath('settings.baseTheme', $theme);
        }
    }

    public function test_unknown_default_map_is_refused_on_create_and_update(): void
    {
        $owner = $this->owner();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);
        $this->api($owner)->postJson("/api/v1/maps/{$map->id}/shares", ['settings' => ['baseTheme' => 'mapbox://styles/evil/x']])
            ->assertUnprocessable();
        $share = $this->api($owner)->postJson("/api/v1/maps/{$map->id}/shares", [])->assertCreated()->json();
        $this->api($owner)->putJson("/api/v1/maps/{$map->id}/shares/{$share['id']}", ['settings' => ['baseTheme' => 'nonsens']])
            ->assertUnprocessable();
    }

    public function test_default_map_can_be_changed_and_cleared_without_touching_other_settings(): void
    {
        $owner = $this->owner();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);
        $share = $this->api($owner)->postJson("/api/v1/maps/{$map->id}/shares", ['settings' => ['showTable' => true, 'showLines' => false]])->assertCreated()->json();
        $url = "/api/v1/maps/{$map->id}/shares/{$share['id']}";

        $this->api($owner)->putJson($url, ['settings' => ['baseTheme' => 'satellite-v9']])->assertOk()
            ->assertJsonPath('settings.baseTheme', 'satellite-v9')->assertJsonPath('settings.showLines', false);
        // leeg = terug naar de kaartinstelling
        $res = $this->api($owner)->putJson($url, ['settings' => ['baseTheme' => null]])->assertOk();
        $this->assertNull($res->json('settings.baseTheme'));
        $res->assertJsonPath('settings.showTable', true);
    }

    public function test_a_link_with_only_a_default_map_keeps_the_standard_visibility_settings(): void
    {
        $owner = $this->owner();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);
        $res = $this->api($owner)->postJson("/api/v1/maps/{$map->id}/shares", ['settings' => ['baseTheme' => 'top25']])->assertCreated();
        $res->assertJsonPath('settings.showTable', true)->assertJsonPath('settings.showMarkers', true)->assertJsonPath('settings.showLines', true);
    }

    public function test_only_the_owner_can_set_it(): void
    {
        $owner = $this->owner();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);
        $this->api($this->owner())->postJson("/api/v1/maps/{$map->id}/shares", ['settings' => ['baseTheme' => 'top25']])->assertNotFound();
    }
}
