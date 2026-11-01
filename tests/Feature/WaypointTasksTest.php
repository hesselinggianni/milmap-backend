<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\MapWaypoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aankomsttaken op een waypoint ("Bij het pick-up point: bel de baas").
 *
 * De taken zijn een JSON-lijst op het waypoint. De server stempelt wie/wanneer
 * iets afvinkte (de client mag dat niet opgeven) en laat ook een kijker
 * afvinken, maar niet de lijst zelf wijzigen.
 */
class WaypointTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('Eigenaar');
        $this->map = Map::create(['owner_id' => $this->owner->id, 'title' => 'Kaart', 'status' => 'active']);
    }

    private function makeUser(string $first): User
    {
        return User::create([
            'first_name' => $first, 'last_name' => 'Test', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDays(3),
        ]);
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    private function createWaypoint(array $extra = []): array
    {
        return $this->as($this->owner)->postJson("/api/v1/maps/{$this->map->id}/waypoints", [
            'local_id' => 1786567355963, 'lon' => 5.47, 'lat' => 51.44, 'label' => 'Pick up point',
        ] + $extra)->assertCreated()->json('waypoint');
    }

    public function test_tasks_are_saved_cleaned_and_returned_with_the_waypoint(): void
    {
        $wp = $this->createWaypoint([
            'task_radius' => 80,
            'tasks' => [
                ['id' => 'a1', 'title' => '  Bel de baas  ', 'required' => true, 'done_by' => 999, 'hack' => 'x'],
                ['id' => 'a2', 'title' => '', 'required' => false],          // leeg → weggelaten
                ['id' => 'a1', 'title' => 'Dubbel'],                          // dubbel id → weggelaten
                ['id' => 'a3', 'title' => 'Foto van de lading'],
            ],
        ]);

        $this->assertSame(80, $wp['task_radius']);
        $this->assertCount(2, $wp['tasks']);
        $this->assertSame('Bel de baas', $wp['tasks'][0]['title']);
        $this->assertTrue($wp['tasks'][0]['required']);
        $this->assertFalse($wp['tasks'][1]['required']);
        $this->assertFalse($wp['tasks'][0]['done']);
        $this->assertArrayNotHasKey('hack', $wp['tasks'][0]);
        $this->assertNull($wp['tasks'][0]['done_by'], 'afvinker mag niet door de client worden opgegeven');
    }

    public function test_updating_a_waypoint_without_tasks_keeps_them(): void
    {
        $this->createWaypoint(['tasks' => [['id' => 'a1', 'title' => 'Bel de baas']]]);

        // Een oudere app-versie kent `tasks` niet en stuurt het dus niet mee.
        $wp = $this->as($this->owner)
            ->putJson("/api/v1/maps/{$this->map->id}/waypoints/1786567355963", ['label' => 'Nieuwe naam'])
            ->assertOk()->json('waypoint');

        $this->assertSame('Nieuwe naam', $wp['label']);
        $this->assertSame('Bel de baas', $wp['tasks'][0]['title']);
    }

    public function test_checking_a_task_stamps_who_and_when_and_unchecking_clears_it(): void
    {
        $this->createWaypoint(['tasks' => [['id' => 'a1', 'title' => 'Bel de baas', 'required' => true]]]);
        $url = "/api/v1/maps/{$this->map->id}/waypoints/1786567355963/tasks/a1";

        $task = $this->as($this->owner)->postJson($url, ['done' => true, 'lat' => 51.4417, 'lon' => 5.47, 'accuracy' => 8])
            ->assertOk()->json('waypoint.tasks.0');
        $log = $this->as($this->owner)->getJson("/api/v1/maps/{$this->map->id}/waypoints/1786567355963/tasks-log")->assertOk()->json('events');
        $this->assertCount(1, $log);
        $this->assertSame($this->owner->id, $log[0]['user_id']);
        $this->assertSame('Bel de baas', $log[0]['task_title']);
        $this->assertEquals(1, $log[0]['done']);
        $this->assertEqualsWithDelta(189, $log[0]['distance_m'], 5);
        $this->assertNotNull($log[0]['ip']);
        $this->assertTrue($task['done']);
        $this->assertSame($this->owner->id, $task['done_by']);
        $this->assertSame('Eigenaar', $task['done_by_name']);
        $this->assertNotNull($task['done_at']);

        // Nog eens opslaan met done=true verandert het tijdstip niet.
        $first = $task['done_at'];
        $this->travel(5)->minutes();
        $again = $this->as($this->owner)->putJson("/api/v1/maps/{$this->map->id}/waypoints/1786567355963", [
            'tasks' => [['id' => 'a1', 'title' => 'Bel de baas', 'required' => true, 'done' => true]],
        ])->assertOk()->json('waypoint.tasks.0');
        $this->assertSame($first, $again['done_at']);

        $reopened = $this->as($this->owner)->postJson($url, ['done' => false])->assertOk()->json('waypoint.tasks.0');
        $this->assertFalse($reopened['done']);
        $this->assertNull($reopened['done_at']);
        $this->assertNull($reopened['done_by']);
    }

    public function test_a_viewer_may_check_off_but_not_edit_the_list(): void
    {
        $this->createWaypoint(['tasks' => [['id' => 'a1', 'title' => 'Bel de baas']]]);
        $viewer = $this->makeUser('Kijker');
        MapCollaborator::create([
            'map_id' => $this->map->id, 'user_id' => $viewer->id, 'added_by' => $this->owner->id,
            'role' => 'viewer', 'status' => 'accepted', 'accepted_at' => now(),
        ]);

        $this->as($viewer)
            ->postJson("/api/v1/maps/{$this->map->id}/waypoints/1786567355963/tasks/a1", ['done' => true])
            ->assertOk()
            ->assertJsonPath('waypoint.tasks.0.done_by_name', 'Kijker');

        $this->as($viewer)
            ->putJson("/api/v1/maps/{$this->map->id}/waypoints/1786567355963", ['tasks' => []])
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_check_off_and_an_unknown_task_is_404(): void
    {
        $this->createWaypoint(['tasks' => [['id' => 'a1', 'title' => 'Bel de baas']]]);
        $base = "/api/v1/maps/{$this->map->id}/waypoints/1786567355963/tasks";

        $this->as($this->makeUser('Vreemde'))->postJson("$base/a1", ['done' => true])->assertForbidden();
        $this->as($this->owner)->postJson("$base/nope", ['done' => true])->assertNotFound();
        $this->as($this->owner)->postJson("$base/a1", [])->assertUnprocessable();
    }

    public function test_a_public_share_never_carries_the_tasks(): void
    {
        $this->createWaypoint(['task_radius' => 80, 'tasks' => [['id' => 'a1', 'title' => 'Bel de baas']]]);

        $token = $this->as($this->owner)
            ->postJson("/api/v1/maps/{$this->map->id}/shares", [])
            ->assertCreated()->json('token');

        app('auth')->forgetGuards();
        $wp = $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/share/'.$token)
            ->assertOk()->json('waypoints.0');

        $this->assertSame('Pick up point', $wp['label']);
        $this->assertSame([], $wp['tasks']);
        $this->assertNull($wp['task_radius']);
        $this->assertSame(1, MapWaypoint::count());
    }
}
