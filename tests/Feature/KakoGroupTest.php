<?php

namespace Tests\Feature;

use App\Http\Controllers\KakoGroupController;
use App\Http\Controllers\RouteAssignmentController;
use App\Models\KakoGroup;
use App\Models\RouteAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Deelnemersgroepen (persoonlijk, herbruikbaar) en Connect-apparaten als KAKO-deelnemer.
 * Gebruikers: 1 = Connect-beheerder van org 10 ("Don Bosco"), 2 = gewoon lid van org 10,
 * 3 = beheerder van een andere organisatie (20), 4 = geen Connect, 5 = apparaataccount.
 * Apparaten: 100/101 horen bij org 10, 200 bij org 20.
 */
class KakoGroupTest extends TestCase
{
    private string $mapId = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->string('email')->nullable();
            $t->boolean('connect_device_account')->default(false);
        });
        Schema::create('connect_organizations', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('status')->default('active'); $t->json('management_policy')->nullable();
            $t->timestamp('starts_at')->nullable(); $t->timestamp('ends_at')->nullable(); $t->timestamps();
        });
        Schema::create('connect_licenses', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->timestamp('starts_at')->nullable(); $t->timestamp('ends_at')->nullable();
        });
        Schema::create('connect_members', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('role')->default('member'); $t->string('status')->default('active');
        });
        Schema::create('connect_allowed_devices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->string('name'); $t->string('udid');
            $t->string('platform')->default('ios'); $t->string('assigned_employee')->nullable(); $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('maps', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('owner_id'); $t->string('title');
            $t->unsignedBigInteger('connect_organization_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('route_maps', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('map_id'); $t->uuid('mission_id')->nullable();
            $t->unsignedBigInteger('owner_id'); $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->string('title')->nullable(); $t->date('date')->nullable(); $t->time('time')->nullable();
            $t->string('color')->nullable(); $t->float('declination')->nullable(); $t->float('speed')->default(5);
            $t->json('locations')->nullable(); $t->json('meta')->nullable(); $t->bigInteger('pause_time')->default(0); $t->bigInteger('total_time')->default(0);
            $t->bigInteger('total_distance')->default(0); $t->bigInteger('total_elevation')->default(0);
            \Tests\Support\RouteMapDiscoveryColumns::add($t);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('map_collaborators', function (Blueprint $t) {
            $t->id(); $t->uuid('map_id'); $t->unsignedBigInteger('user_id'); $t->string('role'); $t->string('status');
        });
        Schema::create('missions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('owner_id'); $t->uuid('map_ref_id')->nullable();
            $t->json('map')->nullable(); $t->string('status')->nullable(); $t->unsignedBigInteger('linked_team_id')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->id(); $t->uuid('mission_id'); $t->unsignedBigInteger('user_id'); $t->string('role')->nullable(); $t->string('status');
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('user_id'); $t->string('status');
        });
        (require database_path('migrations/2026_10_17_150000_create_route_assignments_table.php'))->up();
        (require database_path('migrations/2026_10_17_160000_create_route_assignment_sharing_and_log.php'))->up();
        (require database_path('migrations/2026_10_31_100000_create_kako_groups.php'))->up();

        DB::table('users')->insert([
            ['id' => 1, 'first_name' => 'Bea', 'last_name' => 'Beheerder', 'email' => 'bea@school.test', 'connect_device_account' => false],
            ['id' => 2, 'first_name' => 'Lars', 'last_name' => 'Lid', 'email' => 'lars@school.test', 'connect_device_account' => false],
            ['id' => 3, 'first_name' => 'Tom', 'last_name' => 'Elders', 'email' => 'tom@elders.test', 'connect_device_account' => false],
            ['id' => 4, 'first_name' => 'Sam', 'last_name' => 'Zonder', 'email' => 'sam@example.test', 'connect_device_account' => false],
            ['id' => 5, 'first_name' => 'Pad', 'last_name' => 'Apparaat', 'email' => 'x@connect.milmap.nl', 'connect_device_account' => true],
        ]);
        DB::table('connect_organizations')->insert([['id' => 10, 'name' => 'Don Bosco'], ['id' => 20, 'name' => 'Andere school']]);
        DB::table('connect_members')->insert([
            ['organization_id' => 10, 'user_id' => 1, 'role' => 'admin', 'status' => 'active'],
            ['organization_id' => 10, 'user_id' => 2, 'role' => 'member', 'status' => 'active'],
            ['organization_id' => 20, 'user_id' => 3, 'role' => 'owner', 'status' => 'active'],
            ['organization_id' => 10, 'user_id' => 5, 'role' => 'admin', 'status' => 'active'],
        ]);
        DB::table('connect_allowed_devices')->insert([
            ['id' => 100, 'organization_id' => 10, 'name' => 'iPad 01', 'udid' => 'AAAAAAAA-0001', 'assigned_employee' => 'Jan de Vries', 'platform' => 'ios'],
            ['id' => 101, 'organization_id' => 10, 'name' => 'iPad 02', 'udid' => 'AAAAAAAA-0002', 'assigned_employee' => null, 'platform' => 'android'],
            ['id' => 200, 'organization_id' => 20, 'name' => 'iPad X', 'udid' => 'BBBBBBBB-0001', 'assigned_employee' => 'Piet Vreemd', 'platform' => 'ios'],
        ]);
        DB::table('maps')->insert(['id' => $this->mapId, 'owner_id' => 1, 'title' => 'Oefenterrein']);
        // Iedereen mag de kaart bewerken (de kaartrechten worden elders getest); hier gaat het om het apparaatrecht.
        Gate::before(fn (User $user, string $ability, array $args) => ($args[0] ?? null) instanceof \App\Models\Map ? true : null);
        $this->as(1);
    }

    private function as(int $id): void
    {
        $this->actingAs(User::findOrFail($id));
    }

    private function ctl(): KakoGroupController
    {
        return new KakoGroupController();
    }

    private function create(array $payload): array
    {
        return $this->ctl()->store(Request::create('/', 'POST', $payload))->getData(true);
    }

    // ── Groepen ─────────────────────────────────────────────────────────

    public function test_create_trims_names_and_gives_every_member_an_id(): void
    {
        $g = $this->create(['name' => '  Klas 3B  ', 'members' => [
            ['name' => ' Jansen '], ['id' => 'x1', 'name' => 'De Vries'], ['id' => 'x1', 'name' => 'Bakker'],
        ]]);

        $this->assertSame('Klas 3B', $g['name']);
        $this->assertSame(3, $g['member_count']);
        $this->assertSame(['Jansen', 'De Vries', 'Bakker'], array_column($g['members'], 'name'));
        $this->assertSame('x1', $g['members'][1]['id']);
        $this->assertCount(3, array_unique(array_column($g['members'], 'id')), 'dubbele ids worden vervangen');
        $this->assertArrayNotHasKey('device_id', $g['members'][0]);
    }

    public function test_groups_are_personal(): void
    {
        $mine = $this->create(['name' => 'Mijn klas', 'members' => [['name' => 'A']]]);
        $this->as(2);
        $this->create(['name' => 'Andermans klas']);

        $this->assertSame(['Andermans klas'], array_column($this->ctl()->index()->getData(true), 'name'));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->ctl()->update(Request::create('/', 'PUT', ['name' => 'Gekaapt']), $mine['id']);
    }

    public function test_update_renames_and_replaces_members_and_delete_removes(): void
    {
        $g = $this->create(['name' => 'Klas', 'members' => [['name' => 'A'], ['name' => 'B']]]);

        $u = $this->ctl()->update(Request::create('/', 'PUT', ['name' => 'Klas 4', 'members' => [['id' => $g['members'][1]['id'], 'name' => 'B2']]]), $g['id'])->getData(true);
        $this->assertSame('Klas 4', $u['name']);
        $this->assertSame(['B2'], array_column($u['members'], 'name'));
        $this->assertSame($g['members'][1]['id'], $u['members'][0]['id'], 'het lid-id blijft behouden');

        // Alleen hernoemen laat de leden staan.
        $r = $this->ctl()->update(Request::create('/', 'PUT', ['name' => 'Klas 5']), $g['id'])->getData(true);
        $this->assertSame(1, $r['member_count']);

        $this->ctl()->destroy($g['id']);
        $this->assertSame(0, KakoGroup::count());
    }

    public function test_group_names_are_unique_per_user_case_insensitive(): void
    {
        $this->create(['name' => 'Klas 3B']);
        $this->expectException(ValidationException::class);
        $this->create(['name' => 'klas 3b']);
    }

    public function test_another_user_may_reuse_a_name(): void
    {
        $this->create(['name' => 'Klas 3B']);
        $this->as(2);
        $this->assertSame('Klas 3B', $this->create(['name' => 'Klas 3B'])['name']);
    }

    public function test_blank_name_and_member_name_are_rejected(): void
    {
        try {
            $this->create(['name' => '   ']);
            $this->fail('lege naam hoort te falen');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $this->create(['name' => 'Ok', 'members' => [['name' => '']]]);
    }

    // ── Connect-apparaten: wie ziet wat ──────────────────────────────────

    private function devices(array $query = []): array
    {
        return $this->ctl()->connectDevices(Request::create('/', 'GET', $query), app(\App\Services\KakoConnectDevices::class))->getData(true);
    }

    public function test_connect_manager_sees_own_organisation_devices_without_udid(): void
    {
        $d = $this->devices();

        $this->assertSame([['id' => 10, 'name' => 'Don Bosco']], $d['organizations']);
        $this->assertSame([100, 101], array_column($d['devices'], 'id'));
        $this->assertSame('Jan de Vries', $d['devices'][0]['label'], 'de toegewezen persoon is de deelnemersnaam');
        $this->assertSame('iPad 02', $d['devices'][1]['label'], 'zonder toewijzing geldt de apparaatnaam');
        $this->assertNull($d['devices'][1]['assigned_employee']);
        $this->assertStringNotContainsString('AAAAAAAA', json_encode($d), 'het UDID gaat nooit mee');
        $this->assertSame(2, $d['total']);
        $this->assertFalse($d['truncated']);
    }

    public function test_search_matches_device_name_and_assigned_person(): void
    {
        $this->assertSame([100], array_column($this->devices(['q' => 'vries'])['devices'], 'id'));
        $this->assertSame([101], array_column($this->devices(['q' => 'ipad 02'])['devices'], 'id'));
        $this->assertSame([], $this->devices(['q' => 'piet'])['devices'], 'apparaten van een andere organisatie blijven buiten beeld');
    }

    public function test_members_without_manager_role_see_no_devices(): void
    {
        foreach ([2 => 'gewoon lid', 4 => 'geen Connect', 5 => 'apparaataccount'] as $user => $why) {
            $this->as($user);
            $d = $this->devices();
            $this->assertSame([], $d['devices'], $why);
            $this->assertSame([], $d['organizations'], $why);
        }
    }

    public function test_other_organisation_manager_only_sees_own_devices(): void
    {
        $this->as(3);
        $this->assertSame([200], array_column($this->devices()['devices'], 'id'));
        $this->assertSame([], $this->devices(['organization_id' => 10])['devices'], 'een organisatie die je niet beheert levert niets op');
    }

    public function test_inactive_manager_membership_gives_no_access(): void
    {
        DB::table('connect_members')->where('user_id', 1)->update(['status' => 'removed']);
        $this->assertSame([], $this->devices()['devices']);
    }

    // ── Connect-apparaten in een groep ────────────────────────────────────

    public function test_manager_can_link_devices_to_members(): void
    {
        $g = $this->create(['name' => 'Klas', 'members' => [
            ['name' => 'Jan de Vries', 'device_id' => 100], ['name' => 'Handmatig'], ['name' => 'iPad 02', 'device_id' => 101],
        ]]);

        $this->assertSame([100, null, 101], array_map(fn ($m) => $m['device_id'] ?? null, $g['members']));
    }

    public function test_foreign_device_cannot_be_linked(): void
    {
        $this->expectException(ValidationException::class);
        $this->create(['name' => 'Klas', 'members' => [['name' => 'Piet', 'device_id' => 200]]]);
    }

    public function test_non_manager_cannot_link_new_devices_but_keeps_existing_ones(): void
    {
        $g = $this->create(['name' => 'Klas', 'members' => [['id' => 'm1', 'name' => 'Jan', 'device_id' => 100]]]);

        // Bea verliest het beheerrecht: de koppeling blijft staan, een nieuwe lukt niet meer.
        DB::table('connect_members')->where('user_id', 1)->update(['role' => 'member']);
        $kept = $this->ctl()->update(Request::create('/', 'PUT', ['members' => [['id' => 'm1', 'name' => 'Jan (nieuw)', 'device_id' => 100]]]), $g['id'])->getData(true);
        $this->assertSame(100, $kept['members'][0]['device_id']);

        $this->expectException(ValidationException::class);
        $this->ctl()->update(Request::create('/', 'PUT', ['members' => [['name' => 'Jan', 'device_id' => 100], ['name' => 'Nieuw', 'device_id' => 101]]]), $g['id']);
    }

    public function test_the_same_device_cannot_be_two_members(): void
    {
        $this->expectException(ValidationException::class);
        $this->create(['name' => 'Klas', 'members' => [['name' => 'A', 'device_id' => 100], ['name' => 'B', 'device_id' => 100]]]);
    }

    public function test_several_members_without_device_are_fine(): void
    {
        $g = $this->create(['name' => 'Klas', 'members' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C', 'device_id' => null]]]);
        $this->assertSame(3, $g['member_count']);
    }

    // ── Deelnemer in een training ────────────────────────────────────────

    private function storeAssignment(array $participants): array
    {
        return (new RouteAssignmentController())->store(Request::create('/', 'POST', ['title' => 'Nachtloop', 'participants' => $participants, 'settings' => ['group' => ['id' => 7, 'name' => 'Klas 3B']]]), $this->mapId)->getData(true);
    }

    public function test_participant_keeps_device_id_next_to_the_name(): void
    {
        $a = $this->storeAssignment([
            ['id' => 'p1', 'name' => 'Jan de Vries', 'route_code' => '1A', 'device_id' => 100],
            ['id' => 'p2', 'name' => 'Handmatig', 'route_code' => '2B'],
        ]);

        $this->assertSame(100, $a['participants'][0]['device_id']);
        $this->assertArrayNotHasKey('device_id', $a['participants'][1], 'zonder apparaat geen lege sleutel (productie-vergrendeling vergelijkt de lijst)');
        $this->assertSame(['id' => 7, 'name' => 'Klas 3B'], $a['settings']['group']);
    }

    public function test_device_id_is_stored_as_a_number_and_participant_order_is_kept(): void
    {
        // Zonder id (nullable) belandt een deelnemer na validated() normaal achteraan; de volgorde moet blijven.
        $a = $this->storeAssignment([
            ['name' => 'Eerste', 'device_id' => '100'],
            ['id' => 'p2', 'name' => 'Tweede', 'device_id' => null],
            ['id' => 'p3', 'name' => 'Derde'],
        ]);

        $this->assertSame(['Eerste', 'Tweede', 'Derde'], array_column($a['participants'], 'name'));
        $this->assertSame(100, $a['participants'][0]['device_id']);
        $this->assertArrayNotHasKey('device_id', $a['participants'][1], 'null betekent geen koppeling');
    }

    public function test_foreign_device_on_a_participant_is_forbidden(): void
    {
        try {
            $this->storeAssignment([['id' => 'p1', 'name' => 'Piet', 'device_id' => 200]]);
            $this->fail('vreemd apparaat hoort geweigerd te worden');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, RouteAssignment::count());
    }

    public function test_same_device_twice_in_a_training_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->storeAssignment([['id' => 'p1', 'name' => 'A', 'device_id' => 100], ['id' => 'p2', 'name' => 'B', 'device_id' => 100]]);
    }

    public function test_editor_without_device_rights_can_still_save_an_existing_link(): void
    {
        $a = $this->storeAssignment([['id' => 'p1', 'name' => 'Jan', 'device_id' => 100]]);

        // Lars (gewoon lid, mag de kaart bewerken) slaat de training op met de bestaande koppeling.
        $this->as(2);
        $payload = ['participants' => [['id' => 'p1', 'name' => 'Jan hernoemd', 'device_id' => 100]]];
        $res = (new RouteAssignmentController())->update(Request::create('/', 'PUT', $payload), $a['id']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(100, RouteAssignment::find($a['id'])->participants[0]['device_id']);

        // Maar een nieuw apparaat koppelen mag hij niet.
        try {
            (new RouteAssignmentController())->update(Request::create('/', 'PUT', ['participants' => [['id' => 'p1', 'name' => 'Jan', 'device_id' => 101]]]), $a['id']);
            $this->fail('nieuwe koppeling zonder recht hoort te falen');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_linking_and_unlinking_is_logged(): void
    {
        $a = $this->storeAssignment([['id' => 'p1', 'name' => 'Jan']]);
        (new RouteAssignmentController())->update(Request::create('/', 'PUT', ['participants' => [['id' => 'p1', 'name' => 'Jan', 'device_id' => 100]]]), $a['id']);
        (new RouteAssignmentController())->update(Request::create('/', 'PUT', ['participants' => [['id' => 'p1', 'name' => 'Jan']]]), $a['id']);

        $details = \App\Models\RouteAssignmentLog::where('route_assignment_id', $a['id'])->pluck('changes')->map(fn ($d) => is_array($d) ? $d : json_decode((string) $d, true))->flatten()->all();
        $this->assertContains('Jan: Connect-apparaat gekoppeld', $details);
        $this->assertContains('Jan: Connect-apparaat ontkoppeld', $details);
    }
}
