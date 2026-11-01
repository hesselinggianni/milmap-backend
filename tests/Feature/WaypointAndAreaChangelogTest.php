<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\MapWaypoint;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Waypoints en gemarkeerde gebieden delen hetzelfde detailpaneel: een
 * wijzigingslog (wie/wat/wanneer) en aankomsttaken. Waypoints loggen in
 * map_waypoint_revisions (analoog aan report_revisions); een gebied bewaart zijn
 * taken in eigen kolommen, met de afvinker door de server gezet.
 */
class WaypointAndAreaChangelogTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL_ID = 1786567355963;

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

    private function viewer(): User
    {
        $viewer = $this->makeUser('Kijker');
        MapCollaborator::create([
            'map_id' => $this->map->id, 'user_id' => $viewer->id, 'added_by' => $this->owner->id,
            'role' => 'viewer', 'status' => 'accepted', 'accepted_at' => now(),
        ]);
        return $viewer;
    }

    // ── Waypoints ────────────────────────────────────────────────────────────

    private function wpUrl(string $suffix = ''): string
    {
        return "/api/v1/maps/{$this->map->id}/waypoints".($suffix === '' ? '' : '/'.self::LOCAL_ID.$suffix);
    }

    private function createWaypoint(array $extra = []): array
    {
        return $this->as($this->owner)->postJson($this->wpUrl(), [
            'local_id' => self::LOCAL_ID, 'lon' => 5.47, 'lat' => 51.44, 'label' => 'Pick up point',
        ] + $extra)->assertCreated()->json('waypoint');
    }

    private function wpRevisions(?User $as = null): array
    {
        return $this->as($as ?? $this->owner)->getJson($this->wpUrl('/revisions'))->assertOk()->json('revisions');
    }

    public function test_a_new_waypoint_carries_its_creation_time_and_a_created_entry(): void
    {
        $wp = $this->createWaypoint();

        $this->assertNotNull($wp['created_at']);
        $this->assertNotNull($wp['updated_at']);

        $revisions = $this->wpRevisions();
        $this->assertCount(1, $revisions);
        $this->assertSame('created', $revisions[0]['action']);
        $this->assertSame('Eigenaar Test', $revisions[0]['user']['name']);
    }

    public function test_an_edit_logs_only_the_fields_that_changed(): void
    {
        $this->createWaypoint(['color' => '#ff0000', 'props' => ['Frequentie' => '40.5']]);

        $this->as($this->owner)->putJson("/api/v1/maps/{$this->map->id}/waypoints/".self::LOCAL_ID, [
            'label' => 'Verzamelpunt', 'lon' => 5.48, 'lat' => 51.44, 'color' => '#ff0000',
            'props' => ['Frequentie' => '41.0'],
        ])->assertOk();

        $revisions = $this->wpRevisions();
        $this->assertSame(['updated', 'created'], array_column($revisions, 'action'));

        $changes = $revisions[0]['changes'];
        $this->assertEqualsCanonicalizing(['label', 'position', 'props.Frequentie'], array_keys($changes));
        $this->assertSame(['from' => 'Pick up point', 'to' => 'Verzamelpunt'], $changes['label']);
        $this->assertEqualsWithDelta(5.47, $changes['position']['from']['lon'], 1e-6);
        $this->assertEqualsWithDelta(5.48, $changes['position']['to']['lon'], 1e-6);
        $this->assertSame(['from' => '40.5', 'to' => '41.0'], $changes['props.Frequentie']);
    }

    public function test_saving_the_same_waypoint_again_does_not_log_anything(): void
    {
        $this->createWaypoint(['tasks' => [['id' => 'a1', 'title' => 'Bel de baas']]]);

        // De offline-outbox verstuurt een verzoek soms twee keer; en rondingen
        // op de 7e decimaal zijn geen verplaatsing.
        $this->as($this->owner)->postJson($this->wpUrl(), [
            'local_id' => self::LOCAL_ID, 'lon' => 5.47000004, 'lat' => 51.44, 'label' => 'Pick up point',
            'tasks' => [['id' => 'a1', 'title' => 'Bel de baas', 'done' => true]],
        ])->assertCreated();

        $this->assertSame(['created'], array_column($this->wpRevisions(), 'action'));
    }

    public function test_task_titles_and_checking_off_are_logged(): void
    {
        $this->createWaypoint(['tasks' => [['id' => 'a1', 'title' => 'Bel de baas']]]);
        $path = "/api/v1/maps/{$this->map->id}/waypoints/".self::LOCAL_ID;

        $this->as($this->owner)->putJson($path, [
            'tasks' => [['id' => 'a1', 'title' => 'Bel de baas'], ['id' => 'a2', 'title' => 'Foto van de lading']],
        ])->assertOk();
        $this->as($this->owner)->postJson("$path/tasks/a1", ['done' => true])->assertOk();
        $this->as($this->owner)->postJson("$path/tasks/a1", ['done' => false])->assertOk();

        $revisions = $this->wpRevisions();
        $this->assertSame(['task_reopened', 'task_done', 'updated', 'created'], array_column($revisions, 'action'));
        $this->assertSame(['task' => ['title' => 'Bel de baas']], $revisions[1]['changes']);
        $this->assertSame(
            ['from' => ['Bel de baas'], 'to' => ['Bel de baas', 'Foto van de lading']],
            $revisions[2]['changes']['tasks']
        );
    }

    public function test_a_waypoint_from_before_the_log_still_gets_a_created_entry(): void
    {
        $wp = MapWaypoint::create([
            'map_id' => $this->map->id, 'user_id' => $this->owner->id, 'local_id' => self::LOCAL_ID,
            'lon' => 5.47, 'lat' => 51.44, 'label' => 'Oud punt',
        ]);

        $json = $this->as($this->owner)->getJson($this->wpUrl('/revisions'))->assertOk()->json();

        $this->assertSame('Eigenaar Test', $json['created_by']);
        $this->assertSame($wp->created_at->toIso8601String(), $json['created_at']);
        $this->assertCount(1, $json['revisions']);
        $this->assertSame('created', $json['revisions'][0]['action']);
        $this->assertSame('Eigenaar Test', $json['revisions'][0]['user']['name']);
    }

    public function test_the_waypoint_log_is_for_map_members_only(): void
    {
        $this->createWaypoint();

        $this->assertCount(1, $this->wpRevisions($this->viewer()), 'een kijker mag de log lezen');
        $this->as($this->makeUser('Vreemde'))->getJson($this->wpUrl('/revisions'))->assertForbidden();
        $this->as($this->owner)->getJson("/api/v1/maps/{$this->map->id}/waypoints/42/revisions")->assertNotFound();
    }

    // ── Gemarkeerde gebieden: taken ──────────────────────────────────────────

    private function makeReport(): Report
    {
        return Report::create([
            'map_id' => $this->map->id, 'user_id' => $this->owner->id, 'category' => 'hazard', 'type' => 'flood',
            'latitude' => 51.44, 'longitude' => 5.47, 'status' => 'active',
        ]);
    }

    public function test_an_area_saves_cleaned_tasks_and_a_radius(): void
    {
        $report = $this->makeReport();

        $json = $this->as($this->owner)->putJson("/api/v1/reports/{$report->id}", [
            'task_radius' => 120,
            'tasks' => [
                ['id' => 'a1', 'title' => '  Controleer de dijk  ', 'required' => true, 'done_by' => 999, 'hack' => 'x'],
                ['id' => 'a2', 'title' => ''],                 // leeg → weggelaten
                ['id' => 'a1', 'title' => 'Dubbel'],           // dubbel id → weggelaten
                ['id' => 'a3', 'title' => 'Foto nemen', 'done' => true],
            ],
        ])->assertOk()->json();

        $this->assertSame(120, $json['task_radius']);
        $this->assertCount(2, $json['tasks']);
        $this->assertSame('Controleer de dijk', $json['tasks'][0]['title']);
        $this->assertTrue($json['tasks'][0]['required']);
        $this->assertNull($json['tasks'][0]['done_by'], 'afvinker mag niet door de client worden opgegeven');
        $this->assertArrayNotHasKey('hack', $json['tasks'][0]);
        $this->assertSame('Eigenaar', $json['tasks'][1]['done_by_name'], 'de server zet wie afvinkte');
        $this->assertNotNull($json['tasks'][1]['done_at']);

        // Taken staan in de lijst van de kaart én bij het enkele gebied.
        $this->as($this->owner)->getJson("/api/v1/reports/maps/{$this->map->id}")
            ->assertOk()->assertJsonPath('0.tasks.0.title', 'Controleer de dijk')->assertJsonPath('0.task_radius', 120);
    }

    public function test_updating_an_area_without_tasks_keeps_them(): void
    {
        $report = $this->makeReport();
        $this->as($this->owner)->putJson("/api/v1/reports/{$report->id}", [
            'tasks' => [['id' => 'a1', 'title' => 'Controleer de dijk']], 'task_radius' => 80,
        ])->assertOk();

        // Een oudere app-versie kent `tasks` niet en stuurt het dus niet mee.
        $json = $this->as($this->owner)->putJson("/api/v1/reports/{$report->id}", ['description' => 'Water stijgt'])
            ->assertOk()->json();

        $this->assertSame('Controleer de dijk', $json['tasks'][0]['title']);
        $this->assertSame(80, $json['task_radius']);
    }

    public function test_area_task_titles_are_in_the_log_but_checking_off_is_its_own_entry(): void
    {
        $report = $this->makeReport();
        $url = "/api/v1/reports/{$report->id}";

        $this->as($this->owner)->putJson($url, ['tasks' => [['id' => 'a1', 'title' => 'Controleer de dijk']]])->assertOk();
        // Opnieuw opslaan met alleen een vinkje verandert de titels niet: geen 'updated'-regel.
        $this->as($this->owner)->putJson($url, ['tasks' => [['id' => 'a1', 'title' => 'Controleer de dijk', 'done' => true]]])->assertOk();
        $task = $this->as($this->owner)->postJson("$url/tasks/a1", ['done' => false])->assertOk()->json('tasks.0');
        $this->assertFalse($task['done']);
        $this->assertNull($task['done_at']);

        $revisions = $this->as($this->owner)->getJson($url)->assertOk()->json('revisions');
        $this->assertSame(['task_reopened', 'updated'], array_column($revisions, 'action'));
        $this->assertSame(['task' => ['title' => 'Controleer de dijk']], $revisions[0]['changes']);
        $this->assertSame(['from' => [], 'to' => ['Controleer de dijk']], $revisions[1]['changes']['tasks']);
    }

    public function test_checking_an_area_task_stamps_who_and_when(): void
    {
        $report = $this->makeReport();
        $report->update(['tasks' => [['id' => 'a1', 'title' => 'Controleer de dijk', 'required' => true, 'done' => false]]]);
        $url = "/api/v1/reports/{$report->id}/tasks/a1";

        $json = $this->as($this->owner)->postJson($url, ['done' => true])->assertOk()->json();

        $this->assertTrue($json['tasks'][0]['done']);
        $this->assertSame($this->owner->id, $json['tasks'][0]['done_by']);
        $this->assertSame('Eigenaar', $json['tasks'][0]['done_by_name']);
        $this->assertNotNull($json['tasks'][0]['done_at']);
        $this->assertSame('task_done', $json['revisions'][0]['action']);
        $this->assertSame('Eigenaar Test', $json['revisions'][0]['user']['name']);
    }

    public function test_a_viewer_may_check_off_an_area_task_but_not_edit_the_list(): void
    {
        $report = $this->makeReport();
        $report->update(['tasks' => [['id' => 'a1', 'title' => 'Controleer de dijk', 'required' => false, 'done' => false]]]);
        $viewer = $this->viewer();

        $this->as($viewer)->postJson("/api/v1/reports/{$report->id}/tasks/a1", ['done' => true])
            ->assertOk()->assertJsonPath('tasks.0.done_by_name', 'Kijker');
        $this->as($viewer)->putJson("/api/v1/reports/{$report->id}", ['tasks' => []])->assertForbidden();
    }

    public function test_a_stranger_cannot_check_off_and_an_unknown_task_is_404(): void
    {
        $report = $this->makeReport();
        $report->update(['tasks' => [['id' => 'a1', 'title' => 'Controleer de dijk', 'required' => false, 'done' => false]]]);
        $base = "/api/v1/reports/{$report->id}/tasks";

        $this->as($this->makeUser('Vreemde'))->postJson("$base/a1", ['done' => true])->assertForbidden();
        $this->as($this->owner)->postJson("$base/nope", ['done' => true])->assertNotFound();
        $this->as($this->owner)->postJson("$base/a1", [])->assertUnprocessable();
    }
}
