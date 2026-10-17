<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionLiveEditingTest extends TestCase
{
    private string $missionId;

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
            $t->string('name')->default('Mission'); $t->string('status')->default('planning');
            $t->string('linked_team_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->id(); $t->uuid('mission_id'); $t->integer('user_id'); $t->string('role'); $t->string('status');
        });
        (require database_path('migrations/2026_10_17_120000_create_mission_field_locks_table.php'))->up();

        DB::table('users')->insert([
            ['id' => 10, 'first_name' => 'Anna'],
            ['id' => 20, 'first_name' => 'Bram'],
            ['id' => 30, 'first_name' => 'Cas'],
        ]);
        $this->missionId = (string) Str::uuid();
        DB::table('missions')->insert([
            'id' => $this->missionId, 'owner_id' => 10,
            'ogroup' => json_encode(['warning_order' => ['situation' => 'oud', 'mission' => ''], 'debrief_questions' => ['x']]),
        ]);
        DB::table('mission_collaborators')->insert([
            ['mission_id' => $this->missionId, 'user_id' => 20, 'role' => 'editor', 'status' => 'accepted'],
            ['mission_id' => $this->missionId, 'user_id' => 30, 'role' => 'viewer', 'status' => 'accepted'],
        ]);
    }

    private function as(int $id): self
    {
        $user = User::find($id);
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    private function url(): string
    {
        return '/api/v1/missions/'.$this->missionId.'/live';
    }

    private function sync(array $body)
    {
        if (isset($body['patches'])) {
            $body['patches'] = json_encode($body['patches']);
        }

        return $this->postJson($this->url(), $body);
    }

    private function ogroup(): array
    {
        return json_decode(DB::table('missions')->where('id', $this->missionId)->value('ogroup'), true);
    }

    public function test_patches_touch_only_their_path_and_keep_whitespace(): void
    {
        $this->as(20)->sync(['patches' => [
            ['path' => ['warning_order', 'mission'], 'value' => '  aanval '],
            ['path' => ['warning_order', 'situation'], 'value' => ''],
        ]])->assertOk()->assertJsonPath('rejected', []);

        $og = $this->ogroup();
        $this->assertSame('  aanval ', $og['warning_order']['mission']);
        $this->assertSame('', $og['warning_order']['situation']);
        $this->assertSame(['x'], $og['debrief_questions'], 'andere sleutels blijven staan');
    }

    public function test_lock_blocks_patches_from_others_and_hash_skips_unchanged_blob(): void
    {
        $this->as(10)->sync(['claim' => ['warning_order.situation']])->assertOk()
            ->assertJsonPath('claim_failed', [])
            ->assertJsonPath('locks.0.mine', true);

        $res = $this->as(20)->sync([
            'claim'   => ['warning_order.situation'],
            'patches' => [
                ['path' => ['warning_order', 'situation'], 'value' => 'overschreven'],
                ['path' => ['warning_order', 'mission'], 'value' => 'mag wel'],
            ],
        ])->assertOk()
            ->assertJsonPath('claim_failed', ['warning_order.situation'])
            ->assertJsonPath('rejected', ['warning_order.situation'])
            ->assertJsonPath('locks.0.user.name', 'Anna');

        // Een ouder pad (hele warning_order) overlapt de lock ook.
        $this->sync(['patches' => [['path' => ['warning_order'], 'value' => []]]])
            ->assertJsonPath('rejected', ['warning_order']);

        $this->assertSame('oud', $this->ogroup()['warning_order']['situation']);
        $this->assertSame('mag wel', $this->ogroup()['warning_order']['mission']);

        $hash = $res->json('hash');
        $this->getJson($this->url().'?hash='.$hash)->assertOk()->assertJsonMissingPath('ogroup');
        $this->getJson($this->url().'?hash=anders')->assertOk()->assertJsonPath('ogroup.warning_order.mission', 'mag wel');
    }

    public function test_takeover_request_allow_and_deny(): void
    {
        $this->as(10)->sync(['claim' => ['rv.location']]);

        $this->as(20)->sync(['takeover' => 'rv.location'])
            ->assertJsonPath('locks.0.takeover.mine', true);

        // Houder ziet het verzoek en weigert.
        $this->as(10)->sync(['held' => ['rv.location']])
            ->assertJsonPath('locks.0.takeover.user.name', 'Bram')
            ->assertJsonPath('locks.0.takeover.mine', false);
        $this->sync(['respond' => [['field' => 'rv.location', 'allow' => false]]]);

        // Aanvrager krijgt de weigering precies één keer.
        $this->as(20)->getJson($this->url())->assertJsonPath('denied.0.field', 'rv.location');
        $this->getJson($this->url())->assertJsonPath('denied', []);

        // Opnieuw vragen → nu toestaan → lock gaat over.
        $this->sync(['takeover' => 'rv.location']);
        $this->as(10)->sync([
            'patches' => [['path' => ['rv', 'location'], 'value' => 'laatste tekst houder']],
            'respond' => [['field' => 'rv.location', 'allow' => true]],
        ])->assertJsonPath('rejected', [])->assertJsonPath('locks.0.mine', false);

        $this->as(20)->getJson($this->url())->assertJsonPath('locks.0.mine', true);
        $this->assertSame('laatste tekst houder', $this->ogroup()['rv']['location']);
    }

    public function test_unanswered_takeover_and_expired_lock_are_released(): void
    {
        $this->as(10)->sync(['claim' => ['timeline']]);
        $this->as(20)->sync(['takeover' => 'timeline']);

        $this->travel(16)->seconds();
        $this->getJson($this->url())->assertJsonPath('locks.0.mine', true);

        $this->travel(31)->seconds();
        $this->getJson($this->url())->assertJsonPath('locks', []);
    }

    public function test_release_and_viewer_rights(): void
    {
        $this->as(20)->sync(['claim' => ['participants']])->assertJsonPath('locks.0.mine', true);
        $this->sync(['release' => ['participants']])->assertJsonPath('locks', []);

        $this->as(30)->getJson($this->url())->assertOk();
        $this->sync(['claim' => ['participants']])->assertForbidden();
        $this->sync(['patches' => [['path' => ['x'], 'value' => 1]]])->assertForbidden();
        $this->sync(['claim' => ['../etc']])->assertForbidden();

        $this->as(20)->sync(['claim' => ['bad path!']])->assertStatus(422);
    }
}
