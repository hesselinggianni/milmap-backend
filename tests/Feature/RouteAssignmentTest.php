<?php

namespace Tests\Feature;

use App\Http\Controllers\RouteAssignmentController;
use App\Models\Map;
use App\Models\RouteMap;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteAssignmentTest extends TestCase
{
    private string $mapId = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->string('email')->nullable(); });
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
            $t->timestamps(); $t->softDeletes();
        });
        // Het overzicht vraagt MapAccess om de toegankelijke kaarten.
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
        (require database_path('migrations/2026_10_17_170000_create_kako_folders.php'))->up();

        DB::table('users')->insert([
            ['id' => 1, 'first_name' => 'Olaf', 'last_name' => 'Eigenaar', 'email' => 'olaf@example.test'],
            ['id' => 2, 'first_name' => 'Sam', 'last_name' => 'Vreemd', 'email' => 'sam@example.test'],
        ]);
        DB::table('maps')->insert(['id' => $this->mapId, 'owner_id' => 1, 'title' => 'Oefenterrein']);
        // De echte MapPolicy (medewerkers, missies, teams) wordt elders getest;
        // hier telt alleen dat de controller via de kaart autoriseert.
        Gate::before(fn (User $user, string $ability, array $args) => ($args[0] ?? null) instanceof Map
            ? (int) $args[0]->owner_id === (int) $user->id
            : null);
        $this->actingAs(User::findOrFail(1));
    }

    private function route(string $code): array
    {
        return [
            'code' => $code,
            'color' => '#2b7fff',
            'speed' => 4,
            'total_distance' => 5400,
            'locations' => [
                ['lat' => 51.5, 'lon' => 5.3, 'checkpoint' => ['enabled' => true, 'label' => '1']],
                ['lat' => 51.51, 'lon' => 5.31, 'checkpoint' => ['enabled' => true, 'label' => '2']],
            ],
        ];
    }

    private function store(array $payload): array
    {
        return (new RouteAssignmentController())
            ->store(Request::create('/', 'POST', $payload), $this->mapId)
            ->getData(true);
    }

    public function test_store_creates_assignment_with_linked_route_maps(): void
    {
        $data = $this->store([
            'title' => 'Nachtloop',
            'type' => 'exam',
            'settings' => ['pace' => ['exam' => ['day' => 720, 'night' => 960]]],
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '1a']],
            'routes' => [$this->route('1a'), $this->route('2b')],
        ]);

        $this->assertSame('exam', $data['type']);
        $this->assertCount(2, $data['route_maps']);
        $this->assertSame(['1a', '2b'], RouteMap::orderBy('route_code')->pluck('route_code')->all());
        $this->assertSame(2, RouteMap::where('route_assignment_id', $data['id'])->count());
        $this->assertSame('Nachtloop · 1a', RouteMap::where('route_code', '1a')->value('title'));
    }

    public function test_checkpoints_and_path_bends_survive_validation(): void
    {
        $route = $this->route('A');
        $route['kind'] = 'paths';
        $route['locations'][0]['via'] = [['lat' => 51.505, 'lon' => 5.305]];

        $this->store(['title' => 'Paden', 'routes' => [$route]]);

        $rm = RouteMap::first();
        $this->assertSame('paths', $rm->meta['kind']);
        $this->assertTrue($rm->locations[0]['checkpoint']['enabled']);
        $this->assertSame('1', $rm->locations[0]['checkpoint']['label']);
        $this->assertSame(51.505, $rm->locations[0]['via'][0]['lat']);
    }

    public function test_participant_order_and_result_are_kept(): void
    {
        $data = $this->store([
            'title' => 'Examen',
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '1A', 'order' => 'paths_first',
                'result' => ['posts' => ['3' => 1, '7' => 0], 'finish_time' => '22:41']]],
            'routes' => [$this->route('1')],
        ]);

        $this->assertSame('paths_first', $data['participants'][0]['order']);
        $this->assertSame('22:41', $data['participants'][0]['result']['finish_time']);
    }

    public function test_update_with_routes_replaces_and_trashes_old_routes(): void
    {
        $data = $this->store(['title' => 'Dagloop', 'routes' => [$this->route('1a'), $this->route('2b')]]);
        $oldIds = RouteMap::pluck('id')->all();

        (new RouteAssignmentController())->update(
            Request::create('/', 'PUT', ['routes' => [$this->route('1a')]]),
            $data['id']
        );

        $this->assertSame(1, RouteMap::where('route_assignment_id', $data['id'])->count());
        // Oude routes in de prullenbak én losgekoppeld: terughalen geeft geen dubbele "1a".
        $trashed = RouteMap::onlyTrashed()->whereIn('id', $oldIds)->get();
        $this->assertCount(2, $trashed);
        $this->assertTrue($trashed->every(fn ($r) => $r->route_assignment_id === null && $r->route_code === null));
    }

    public function test_update_without_routes_keeps_routes(): void
    {
        $data = $this->store(['title' => 'Dagloop', 'routes' => [$this->route('1a')]]);

        (new RouteAssignmentController())->update(
            Request::create('/', 'PUT', ['participants' => [['name' => 'De Vries', 'route_code' => '1a']]]),
            $data['id']
        );

        $this->assertSame(1, RouteMap::where('route_assignment_id', $data['id'])->count());
        $this->assertSame('Dagloop', DB::table('route_assignments')->value('title'));
    }

    public function test_destroy_keeps_routes_as_loose_route_maps_by_default(): void
    {
        $data = $this->store(['title' => 'Dagloop', 'routes' => [$this->route('1a')]]);

        (new RouteAssignmentController())->destroy(Request::create('/', 'DELETE'), $data['id']);

        $this->assertDatabaseCount('route_assignments', 0);
        $this->assertSame(1, RouteMap::count());
        $this->assertNull(RouteMap::first()->route_assignment_id);
    }

    public function test_destroy_with_routes_trashes_them(): void
    {
        $data = $this->store(['title' => 'Dagloop', 'routes' => [$this->route('1a')]]);

        (new RouteAssignmentController())->destroy(Request::create('/', 'DELETE', ['with_routes' => 1]), $data['id']);

        $this->assertSame(0, RouteMap::count());
        $this->assertSame(1, RouteMap::onlyTrashed()->count());
    }

    public function test_stranger_cannot_create_on_someone_elses_map(): void
    {
        $this->actingAs(User::findOrFail(2));
        $this->expectException(AuthorizationException::class);
        $this->store(['title' => 'Niet van mij', 'routes' => [$this->route('1a')]]);
    }

    private function ctl(): RouteAssignmentController
    {
        return new RouteAssignmentController();
    }

    public function test_creating_writes_a_log_entry_with_the_user_name(): void
    {
        $data = $this->store(['title' => 'Log', 'participants' => [['id' => 'p1', 'name' => 'Jansen']], 'routes' => [$this->route('1')]]);
        $log = DB::table('route_assignment_logs')->where('route_assignment_id', $data['id'])->first();
        $this->assertSame('created', $log->action);
        $this->assertSame('Olaf Eigenaar', $log->user_name);
        $this->assertStringContainsString('1 deelnemers, 1 routes', $log->summary);
    }

    public function test_update_logs_exactly_what_changed(): void
    {
        $data = $this->store([
            'title' => 'Log',
            'settings' => ['pace' => ['training' => ['day' => 720]], 'poolIds' => ['a', 'b']],
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '1A']],
        ]);

        $this->ctl()->update(Request::create('/', 'PUT', [
            'title' => 'Nieuwe naam',
            'settings' => ['pace' => ['training' => ['day' => 660]], 'poolIds' => ['a', 'b', 'c']],
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '2B', 'result' => ['finish_time' => '22:41']]],
        ]), $data['id']);

        $changes = json_decode(DB::table('route_assignment_logs')->where('action', 'updated')->value('changes'), true);
        $this->assertContains('Naam gewijzigd: "Log" → "Nieuwe naam"', $changes);
        $this->assertContains('Tempo per km › training › day: 720 → 660', $changes);
        $this->assertContains('Pool van posten: 2 → 3 posten', $changes);
        $this->assertContains('Jansen: route 1A → 2B', $changes);
        $this->assertContains('Jansen: beoordeling — binnenkomsttijd — → 22:41', $changes);
    }

    public function test_emergency_procedure_is_kept_and_logged(): void
    {
        $data = $this->store(['title' => 'Nood', 'settings' => ['emergency' => '']]);
        $this->ctl()->update(Request::create('/', 'PUT', ['settings' => ['emergency' => 'Bel 112, daarna de instructeur op kanaal 3.']]), $data['id']);
        $this->assertSame('Bel 112, daarna de instructeur op kanaal 3.', json_decode(DB::table('route_assignments')->value('settings'), true)['emergency']);
        $changes = json_decode(DB::table('route_assignment_logs')->where('action', 'updated')->value('changes'), true);
        $this->assertContains('Noodprocedure: — → Bel 112, daarna de instructeur op kanaal 3.', $changes);
    }

    public function test_post_descriptions_are_logged_with_the_post_label(): void
    {
        $posts = [['id' => '7', 'label' => 'Post 7']];
        $data = $this->store(['title' => 'Omschr', 'settings' => ['posts' => $posts, 'descriptions' => []]]);
        $this->ctl()->update(Request::create('/', 'PUT', ['settings' => ['posts' => $posts, 'descriptions' => ['7' => 'NO van Y-splitsing']]]), $data['id']);
        $changes = json_decode(DB::table('route_assignment_logs')->where('action', 'updated')->value('changes'), true);
        $this->assertContains('Omschrijving post Post 7: — → NO van Y-splitsing', $changes);
    }

    public function test_noop_update_writes_no_log_entry(): void
    {
        $data = $this->store(['title' => 'Stil', 'settings' => ['offset' => 1]]);
        $this->ctl()->update(Request::create('/', 'PUT', ['title' => 'Stil', 'settings' => ['offset' => 1]]), $data['id']);
        $this->assertSame(0, DB::table('route_assignment_logs')->where('action', 'updated')->count());
    }

    public function test_invited_editor_can_edit_after_accepting_but_not_before(): void
    {
        $data = $this->store(['title' => 'Samen', 'routes' => [$this->route('1')]]);
        $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'sam@example.test', 'role' => 'editor']), $data['id']);

        $this->actingAs(User::findOrFail(2));
        try {
            $this->ctl()->update(Request::create('/', 'PUT', ['title' => 'Gekaapt']), $data['id']);
            $this->fail('pending invitee must not edit');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->ctl()->respond($data['id'], 'accept');
        $this->ctl()->update(Request::create('/', 'PUT', ['title' => 'Samen bewerkt']), $data['id']);

        $this->assertSame('Samen bewerkt', DB::table('route_assignments')->value('title'));
        $entry = DB::table('route_assignment_logs')->where('action', 'updated')->first();
        $this->assertSame('Sam Vreemd', $entry->user_name);
    }

    public function test_invited_viewer_can_read_but_not_edit_and_not_manage_sharing(): void
    {
        $data = $this->store(['title' => 'Kijken']);
        $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'sam@example.test', 'role' => 'viewer']), $data['id']);
        $this->actingAs(User::findOrFail(2));
        $this->ctl()->respond($data['id'], 'accept');

        $this->assertSame('viewer', $this->ctl()->show($data['id'])->getData(true)['role']);
        foreach ([
            fn () => $this->ctl()->update(Request::create('/', 'PUT', ['title' => 'x']), $data['id']),
            fn () => $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'olaf@example.test', 'role' => 'editor']), $data['id']),
            fn () => $this->ctl()->destroy(Request::create('/', 'DELETE'), $data['id']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('viewer must be refused');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_stale_version_gives_conflict_instead_of_overwriting(): void
    {
        $data = $this->store(['title' => 'Race']);
        $res = $this->ctl()->update(Request::create('/', 'PUT', ['title' => 'Race', 'expected_version' => $data['version'] - 5]), $data['id']);
        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('Race', DB::table('route_assignments')->value('title'));
    }

    public function test_unknown_email_cannot_be_invited_and_overview_lists_pending_invites(): void
    {
        $data = $this->store(['title' => 'Overzicht']);
        $res = $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'niemand@example.test', 'role' => 'editor']), $data['id']);
        $this->assertSame(404, $res->getStatusCode());

        $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'sam@example.test', 'role' => 'editor']), $data['id']);
        $this->actingAs(User::findOrFail(2));
        $list = $this->ctl()->overview()->getData(true);
        $this->assertCount(1, $list);
        $this->assertSame('invited', $list[0]['role']);
        $this->assertSame('Olaf Eigenaar', $list[0]['owner_name']);
    }

    public function test_production_mode_locks_everything_except_results(): void
    {
        $data = $this->store([
            'title' => 'Prod',
            'settings' => ['status' => 'draft', 'pace' => ['training' => ['day' => 720]], 'scoring' => ['pointsPerPost' => 10]],
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '1A']],
            'routes' => [$this->route('1')],
        ]);
        $live = ['status' => 'live', 'pace' => ['training' => ['day' => 720]], 'scoring' => ['pointsPerPost' => 10]];
        $this->ctl()->update(Request::create('/', 'PUT', ['settings' => $live]), $data['id']);
        $this->assertStringContainsString('Training in productie gezet', DB::table('route_assignment_logs')->where('action', 'updated')->orderByDesc('id')->value('summary'));

        $locked = fn (array $payload) => $this->ctl()->update(Request::create('/', 'PUT', $payload), $data['id'])->getStatusCode();
        $this->assertSame(423, $locked(['settings' => array_replace_recursive($live, ['scoring' => ['pointsPerPost' => 99]])]), 'puntentelling');
        $this->assertSame(423, $locked(['routes' => [$this->route('1')]]), 'routes genereren');
        $this->assertSame(423, $locked(['title' => 'Andere naam']), 'naam');
        $this->assertSame(423, $locked(['participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '2B']]]), 'routekoppeling');
        $this->assertSame(423, $this->ctl()->destroy(Request::create('/', 'DELETE'), $data['id'])->getStatusCode(), 'verwijderen');

        // A4-printopties zijn presentatie en blijven ook in productie aanpasbaar.
        $this->assertSame(200, $this->ctl()->update(Request::create('/', 'PUT', ['settings' => $live + ['print' => ['showMil' => false]]]), $data['id'])->getStatusCode());
        $live['print'] = ['showMil' => false];

        // Beoordelen mag wel (en view/seed zijn geen echte instellingen).
        $res = $this->ctl()->update(Request::create('/', 'PUT', [
            'settings' => $live + ['view' => 'night', 'seed' => 5],
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '1A', 'result' => ['finish_time' => '22:10']]],
        ]), $data['id']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('22:10', json_decode(DB::table('route_assignments')->value('participants'), true)[0]['result']['finish_time']);
    }

    public function test_only_the_owner_can_switch_modes(): void
    {
        $data = $this->store(['title' => 'Modus', 'settings' => ['status' => 'draft']]);
        $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'sam@example.test', 'role' => 'editor']), $data['id']);
        $this->actingAs(User::findOrFail(2));
        $this->ctl()->respond($data['id'], 'accept');
        $res = $this->ctl()->update(Request::create('/', 'PUT', ['settings' => ['status' => 'live']]), $data['id']);
        $this->assertSame(403, $res->getStatusCode());
        $this->actingAs(User::findOrFail(1));
        $this->assertSame(200, $this->ctl()->update(Request::create('/', 'PUT', ['settings' => ['status' => 'live']]), $data['id'])->getStatusCode());
        $this->assertSame(200, $this->ctl()->update(Request::create('/', 'PUT', ['settings' => ['status' => 'draft']]), $data['id'])->getStatusCode());
    }

    public function test_folders_are_personal_and_overview_shows_who_has_access(): void
    {
        $data = $this->store(['title' => 'Mappen']);
        $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'sam@example.test', 'role' => 'viewer']), $data['id']);
        $folders = new \App\Http\Controllers\KakoFolderController();

        $mine = $folders->store(Request::create('/', 'POST', ['name' => 'Oirschot']))->getData(true);
        $folders->assign(Request::create('/', 'PUT', ['folder_id' => $mine['id']]), $data['id']);
        $row = $this->ctl()->overview()->getData(true)[0];
        $this->assertSame($mine['id'], $row['folder_id']);
        $this->assertSame(['owner', 'viewer'], collect($row['access'])->pluck('role')->all());
        $this->assertSame('pending', $row['access'][1]['status']);

        // De ander ziet jouw map niet en kan hem niet gebruiken.
        $this->actingAs(User::findOrFail(2));
        $this->ctl()->respond($data['id'], 'accept');
        $this->assertNull($this->ctl()->overview()->getData(true)[0]['folder_id']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $folders->assign(Request::create('/', 'PUT', ['folder_id' => $mine['id']]), $data['id']);
    }

    public function test_accepted_collaborator_without_map_access_can_list_but_stranger_cannot(): void
    {
        $data = $this->store(['title' => 'Zonder kaarttoegang']);
        // Stub: gebruiker 2 heeft geen toegang tot de kaart zelf (zie Gate::before).
        $this->ctl()->invite(Request::create('/', 'POST', ['email' => 'sam@example.test', 'role' => 'editor']), $data['id']);
        $this->actingAs(User::findOrFail(2));

        try {
            $this->ctl()->index($this->mapId);
            $this->fail('pending invitee must not list');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->ctl()->respond($data['id'], 'accept');
        $list = $this->ctl()->index($this->mapId)->getData(true);
        $this->assertCount(1, $list);
        $this->assertSame($data['id'], $list[0]['id']);
    }
}
