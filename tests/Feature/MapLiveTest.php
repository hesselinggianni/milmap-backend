<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Samen een kaart beheren: GET /maps/{id}/live geeft per onderdeel een
 * handtekening die verschuift zodra iemand anders iets toevoegt, wijzigt of
 * verwijdert — de client haalt dan dat onderdeel opnieuw op.
 */
class MapLiveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $editor;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('Eigenaar');
        $this->editor = $this->makeUser('Editor');
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

    private function invite(User $user, string $role = 'editor', string $status = 'accepted'): void
    {
        MapCollaborator::create([
            'map_id' => $this->map->id, 'user_id' => $user->id, 'added_by' => $this->owner->id,
            'role' => $role, 'status' => $status, 'accepted_at' => $status === 'accepted' ? now() : null,
        ]);
    }

    private function live(User $as): array
    {
        return $this->as($as)->getJson("/api/v1/maps/{$this->map->id}/live")->assertOk()->json();
    }

    private function addWaypoint(User $as, int $localId = 1): void
    {
        $this->as($as)->postJson("/api/v1/maps/{$this->map->id}/waypoints", [
            'local_id' => $localId, 'lon' => 5.47, 'lat' => 51.44, 'label' => 'Punt '.$localId,
        ])->assertCreated();
    }

    public function test_a_waypoint_added_by_the_editor_shifts_the_signature_the_owner_sees(): void
    {
        $this->invite($this->editor);
        $voor = $this->live($this->owner);

        $this->addWaypoint($this->editor);
        $na = $this->live($this->owner);

        $this->assertNotSame($voor['sig']['waypoints'], $na['sig']['waypoints']);
        $this->assertSame($voor['sig']['reports'], $na['sig']['reports'], 'andere onderdelen blijven gelijk');
        $this->assertSame($voor['sig']['routemaps'], $na['sig']['routemaps']);
        $this->assertStringStartsWith('1|', $na['sig']['waypoints']);
    }

    public function test_a_removed_waypoint_shifts_the_signature_again(): void
    {
        $this->invite($this->editor);
        $this->addWaypoint($this->editor, 1);
        $this->addWaypoint($this->editor, 2);
        $voor = $this->live($this->owner)['sig']['waypoints'];

        $this->as($this->editor)->deleteJson("/api/v1/maps/{$this->map->id}/waypoints/2")->assertSuccessful();

        $na = $this->live($this->owner)['sig']['waypoints'];
        $this->assertNotSame($voor, $na);
        $this->assertStringStartsWith('1|', $na);
    }

    public function test_the_signature_is_stable_while_nothing_changes(): void
    {
        $this->addWaypoint($this->owner);
        $this->travel(1)->minutes();

        $this->assertSame($this->live($this->owner), $this->live($this->owner));
    }

    public function test_a_fresh_write_keeps_the_signature_hot_so_a_second_edit_in_the_same_second_is_not_missed(): void
    {
        $this->addWaypoint($this->owner);

        $heet = $this->live($this->owner)['sig']['waypoints'];
        $this->assertStringContainsString('~', $heet, 'vers geschreven → heet');

        // Rustig geworden: de vaste waarde wijkt af van de hete, dus de client haalt de eindstand nog eens op.
        $this->travel(1)->minutes();
        $rustig = $this->live($this->owner)['sig']['waypoints'];
        $this->assertStringNotContainsString('~', $rustig);
        $this->assertNotSame($heet, $rustig);
    }

    public function test_shared_is_false_for_a_solo_owner_and_true_once_someone_accepted(): void
    {
        $this->assertFalse($this->live($this->owner)['shared']);

        $this->invite($this->editor, 'editor', 'pending');
        $this->assertFalse($this->live($this->owner)['shared'], 'een openstaande uitnodiging telt nog niet mee');

        MapCollaborator::where('map_id', $this->map->id)->update(['status' => 'accepted']);
        $this->assertTrue($this->live($this->owner)['shared']);
        $this->assertTrue($this->live($this->editor)['shared']);
    }

    public function test_a_map_shared_through_a_mission_counts_as_shared_for_its_owner(): void
    {
        $this->assertFalse($this->live($this->owner)['shared']);

        // Deelnemers van een missie hebben geen map_collaborators-rij; zonder deze regel
        // pollt de eigenaar zo'n kaart op het rustige tempo en ziet hij wijzigingen pas na 30 s.
        Mission::create([
            'owner_id' => $this->owner->id, 'name' => 'Oefening', 'status' => 'active',
            'map' => ['id' => $this->map->id, 'source' => 'server'],
        ]);

        $this->assertTrue($this->live($this->owner)['shared']);
    }

    public function test_a_mission_of_someone_else_does_not_make_the_map_shared(): void
    {
        $other = $this->makeUser('Andere');
        Mission::create([
            'owner_id' => $other->id, 'name' => 'Niet van de eigenaar', 'status' => 'active',
            'map' => ['id' => $this->map->id, 'source' => 'server'],
        ]);

        $this->assertFalse($this->live($this->owner)['shared']);
    }

    public function test_a_viewer_can_follow_along_but_a_stranger_cannot(): void
    {
        $viewer = $this->makeUser('Kijker');
        $this->invite($viewer, 'viewer');
        $this->live($viewer);

        $this->as($this->makeUser('Vreemde'))
            ->getJson("/api/v1/maps/{$this->map->id}/live")
            ->assertForbidden();
    }

    public function test_an_unknown_map_is_a_404(): void
    {
        $this->as($this->owner)->getJson('/api/v1/maps/'.Str::uuid().'/live')->assertNotFound();
    }
}
