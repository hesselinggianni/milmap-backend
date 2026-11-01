<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Commandant → meerdere teams, elk team zijn eigen versie van de uitvoering.
 * Anna (10) = commandant/eigenaar, Bram (20) = lid van team A, Cas (30) = lid van team B,
 * Dirk (40) = gast in team A, Eva (50) = geen team.
 */
class MissionTeamsTest extends TestCase
{
    private string $missionId;
    private string $teamA;
    private string $teamB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Cache::flush();
        $this->withoutMiddleware([
            \App\Http\Middleware\TrackLastSeen::class,
            \App\Http\Middleware\EnsureEmailVerified::class,
            \App\Http\Middleware\EnsureNotViewOnly::class,
        ]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('first_name')->nullable(); $t->string('last_name')->nullable();
            $t->string('email')->nullable(); $t->string('avatar_path')->nullable();
        });
        Schema::create('missions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('owner_id'); $t->json('ogroup')->nullable();
            $t->string('name')->default('Mission'); $t->string('status')->default('active');
            $t->string('linked_team_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->id(); $t->uuid('mission_id'); $t->integer('user_id'); $t->string('role'); $t->string('status');
        });
        Schema::create('teams', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('owner_id'); $t->string('name')->nullable(); $t->string('color')->nullable();
            $t->timestamps();
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('team_id'); $t->integer('user_id')->nullable(); $t->string('email')->nullable();
            $t->string('role')->default('member'); $t->string('status')->default('active'); $t->timestamps();
        });
        (require database_path('migrations/2026_11_01_100000_create_mission_teams_table.php'))->up();
        (require database_path('migrations/2026_10_17_120000_create_mission_field_locks_table.php'))->up();

        DB::table('users')->insert([
            ['id' => 10, 'first_name' => 'Anna'], ['id' => 20, 'first_name' => 'Bram'], ['id' => 30, 'first_name' => 'Cas'],
            ['id' => 40, 'first_name' => 'Dirk'], ['id' => 50, 'first_name' => 'Eva'],
        ]);
        $this->teamA = (string) Str::uuid();
        $this->teamB = (string) Str::uuid();
        DB::table('teams')->insert([
            ['id' => $this->teamA, 'owner_id' => 10, 'name' => 'Alpha', 'color' => '#ff0000'],
            ['id' => $this->teamB, 'owner_id' => 10, 'name' => 'Bravo', 'color' => '#0000ff'],
        ]);
        DB::table('team_members')->insert([
            ['id' => (string) Str::uuid(), 'team_id' => $this->teamA, 'user_id' => 20, 'role' => 'member'],
            ['id' => (string) Str::uuid(), 'team_id' => $this->teamA, 'user_id' => 40, 'role' => 'guest'],
            ['id' => (string) Str::uuid(), 'team_id' => $this->teamB, 'user_id' => 30, 'role' => 'member'],
        ]);
        $this->missionId = (string) Str::uuid();
        DB::table('missions')->insert([
            'id' => $this->missionId, 'owner_id' => 10, 'status' => 'active',
            'ogroup' => json_encode([
                'warning_order' => ['situation' => 'gezamenlijk'],
                'tlp' => ['steps' => ['receive' => ['status' => 'done']]],
                'team_plans' => [
                    $this->teamA => ['tlp' => ['steps' => ['plan' => ['status' => 'active']]]],
                    $this->teamB => ['ogroup' => ['mission' => ['statement' => 'geheim van B']]],
                ],
            ]),
        ]);
        DB::table('mission_teams')->insert([
            ['id' => (string) Str::uuid(), 'mission_id' => $this->missionId, 'team_id' => $this->teamA, 'sort_order' => 1],
            ['id' => (string) Str::uuid(), 'mission_id' => $this->missionId, 'team_id' => $this->teamB, 'sort_order' => 2],
        ]);
    }

    private function as(int $id): self
    {
        $this->actingAs(User::find($id), 'sanctum');

        return $this;
    }

    private function live(): string
    {
        return '/api/v1/missions/'.$this->missionId.'/live';
    }

    private function sync(array $body)
    {
        if (isset($body['patches'])) {
            $body['patches'] = json_encode($body['patches']);
        }

        return $this->postJson($this->live(), $body);
    }

    private function stored(): array
    {
        return json_decode(DB::table('missions')->where('id', $this->missionId)->value('ogroup'), true);
    }

    public function test_team_member_gets_only_own_version_commander_gets_all(): void
    {
        $og = $this->as(20)->getJson($this->live())->assertOk()->json('ogroup');
        $this->assertSame([$this->teamA], array_keys($og['team_plans']));
        $this->assertSame('gezamenlijk', $og['warning_order']['situation']);

        $og = $this->as(10)->getJson($this->live())->assertOk()->json('ogroup');
        $this->assertEqualsCanonicalizing([$this->teamA, $this->teamB], array_keys($og['team_plans']));

        // Ook de gewone missie-response lekt de andere versie niet.
        $detail = $this->as(30)->getJson('/api/v1/missions/'.$this->missionId)->assertOk()->json('data.ogroup.team_plans');
        $this->assertSame([$this->teamB], array_keys($detail));

        // Iemand zonder team ziet de missie niet eens.
        $this->as(50)->getJson($this->live())->assertForbidden();
    }

    public function test_team_member_can_patch_own_version_only(): void
    {
        $this->as(20)->sync(['patches' => [
            ['path' => ['team_plans', $this->teamA, 'ogroup', 'mission', 'statement'], 'value' => 'onze aanpak'],
            ['path' => ['team_plans', $this->teamB, 'ogroup', 'mission', 'statement'], 'value' => 'gekraakt'],
            ['path' => ['warning_order', 'situation'], 'value' => 'gekraakt'],
            ['path' => ['team_plans', $this->teamA], 'value' => 'hele versie wissen'],
        ]])->assertOk()->assertJsonPath('rejected', []);

        $og = $this->stored();
        $this->assertSame('onze aanpak', $og['team_plans'][$this->teamA]['ogroup']['mission']['statement']);
        $this->assertSame('active', $og['team_plans'][$this->teamA]['tlp']['steps']['plan']['status'], 'rest van de versie blijft');
        $this->assertSame('geheim van B', $og['team_plans'][$this->teamB]['ogroup']['mission']['statement']);
        $this->assertSame('gezamenlijk', $og['warning_order']['situation']);
    }

    public function test_commander_edits_any_team_and_unknown_team_ids_are_ignored(): void
    {
        $other = (string) Str::uuid();
        $this->as(10)->sync(['patches' => [
            ['path' => ['team_plans', $this->teamB, 'tlp', 'steps', 'plan', 'status'], 'value' => 'done'],
            ['path' => ['team_plans', $other, 'tlp', 'steps', 'plan', 'status'], 'value' => 'done'],
            ['path' => ['warning_order', 'situation'], 'value' => 'nieuw'],
        ]])->assertOk();

        $og = $this->stored();
        $this->assertSame('done', $og['team_plans'][$this->teamB]['tlp']['steps']['plan']['status']);
        $this->assertArrayNotHasKey($other, $og['team_plans']);
        $this->assertSame('nieuw', $og['warning_order']['situation']);
    }

    public function test_guest_reads_but_cannot_write_and_inactive_mission_closes_the_door(): void
    {
        $this->as(40)->getJson($this->live())->assertOk()->assertJsonPath('ogroup.team_plans.'.$this->teamA.'.tlp.steps.plan.status', 'active');
        $this->sync(['patches' => [['path' => ['team_plans', $this->teamA, 'x', 'y'], 'value' => 1]]])->assertForbidden();

        DB::table('missions')->update(['status' => 'planning']);
        $this->as(20)->getJson($this->live())->assertForbidden();
    }

    public function test_locks_are_scoped_to_the_team(): void
    {
        $field = 'team_plans.'.$this->teamA.'.ogroup.mission.statement';
        $this->as(20)->sync(['claim' => [$field, 'team_plans.'.$this->teamB.'.ogroup.mission.statement', 'warning_order.situation']])
            ->assertOk()->assertJsonCount(1, 'locks')->assertJsonPath('locks.0.field', $field);

        // De commandant claimt het veld van team B; teamlid B ziet het niet van team A, wel van B.
        $this->as(10)->sync(['claim' => ['team_plans.'.$this->teamB.'.ogroup.mission.statement']])->assertOk();
        $this->as(30)->getJson($this->live())->assertJsonCount(1, 'locks')
            ->assertJsonPath('locks.0.field', 'team_plans.'.$this->teamB.'.ogroup.mission.statement');
        $this->as(20)->getJson($this->live())->assertJsonCount(1, 'locks')->assertJsonPath('locks.0.field', $field);
    }

    public function test_full_put_never_touches_team_versions_and_duplicate_drops_them(): void
    {
        $this->as(10)->putJson('/api/v1/missions/'.$this->missionId, ['ogroup' => [
            'warning_order' => ['situation' => 'via PUT'], 'team_plans' => ['zomaar' => ['x' => 1]],
        ]])->assertOk();

        $og = $this->stored();
        $this->assertSame('via PUT', $og['warning_order']['situation']);
        $this->assertEqualsCanonicalizing([$this->teamA, $this->teamB], array_keys($og['team_plans']));
        $this->assertArrayNotHasKey('tlp', $og, 'de rest van de blob wordt wel vervangen');
    }

    public function test_manage_teams_endpoints(): void
    {
        DB::table('mission_teams')->delete();
        $newTeam = (string) Str::uuid();
        DB::table('teams')->insert(['id' => $newTeam, 'owner_id' => 10, 'name' => 'Charlie', 'color' => '#00ff00']);
        $foreign = (string) Str::uuid();
        DB::table('teams')->insert(['id' => $foreign, 'owner_id' => 99, 'name' => 'Vreemd']);
        $url = '/api/v1/missions/'.$this->missionId.'/teams';

        // Alleen eigen teams koppelen; teamleden mogen niets beheren.
        $this->as(10)->postJson($url, ['team_id' => $foreign])->assertStatus(422);
        $this->as(20)->postJson($url, ['team_id' => $newTeam])->assertForbidden();

        $this->as(10)->postJson($url, ['team_id' => $this->teamA])->assertCreated();
        $this->postJson($url, ['team_id' => $this->teamB])->assertCreated();
        $this->postJson($url, ['team_id' => $this->teamB])->assertOk();
        $this->assertSame(2, DB::table('mission_teams')->count());

        // Laag delen: alleen teams van deze missie, nooit het team zelf.
        $this->putJson($url.'/'.$this->teamA, ['layer_share' => [$this->teamB, $this->teamA, $foreign]])
            ->assertOk()->assertJsonPath('data.0.layer_share', [$this->teamB]);

        // Commandant ziet beide teams met leden; teamlid B ziet zichzelf + de gedeelde laag van A, zonder leden.
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.members.0.name', 'Bram');
        $res = $this->as(30)->getJson($url)->assertOk();
        $res->assertJsonCount(2, 'data');
        $byId = collect($res->json('data'))->keyBy('team_id');
        $this->assertArrayHasKey('members', $byId[$this->teamB]);
        $this->assertArrayNotHasKey('members', $byId[$this->teamA]);
        $this->assertArrayNotHasKey('layer_share', $byId[$this->teamB]);

        // Laag-delen weer uit → team B ziet team A niet meer.
        $this->as(10)->putJson($url.'/'.$this->teamA, ['layer_share' => []])->assertOk();
        $this->as(30)->getJson($url)->assertJsonCount(1, 'data');

        // Loskoppelen haalt het team ook uit andermans delen-lijst.
        $this->as(10)->putJson($url.'/'.$this->teamB, ['layer_share' => [$this->teamA]])->assertOk();
        $this->deleteJson($url.'/'.$this->teamA)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([], json_decode(DB::table('mission_teams')->where('team_id', $this->teamB)->value('layer_share'), true));

        // Het oude enkelvoudig gekoppelde team telt mee en gaat ook weg bij loskoppelen.
        DB::table('missions')->update(['linked_team_id' => $this->teamA]);
        $this->getJson($url)->assertJsonCount(2, 'data');
        $this->deleteJson($url.'/'.$this->teamA)->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull(DB::table('missions')->value('linked_team_id'));
    }

    public function test_every_linked_team_gets_viewer_access_and_list_visibility(): void
    {
        $this->assertSame('viewer', \App\Models\Mission::find($this->missionId)->roleFor(20));
        $this->assertSame('viewer', \App\Models\Mission::find($this->missionId)->roleFor(30));
        $this->assertNull(\App\Models\Mission::find($this->missionId)->roleFor(50));

        $this->as(30)->getJson('/api/v1/missions')->assertOk()->assertJsonCount(1, 'data');
        $this->as(50)->getJson('/api/v1/missions')->assertOk()->assertJsonCount(0, 'data');
    }
}
