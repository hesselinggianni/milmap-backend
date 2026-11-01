<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\PublicProfile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Openbaar profiel + routes delen/klonen. Nadruk op privacy: wat een vreemde
 * NIET mag zien, en dat een clone een los, eigen exemplaar is.
 */
class PublicProfileRoutesTest extends TestCase
{
    private const OWNER = 10;
    private const OTHER = 20;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');

        $this->withoutMiddleware([
            \App\Http\Middleware\TrackLastSeen::class,
            \App\Http\Middleware\EnsureEmailVerified::class,
            \App\Http\Middleware\EnsureNotViewOnly::class,
        ]);

        Schema::create('permissions', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('avatar_path')->nullable();
            $t->string('language')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->string('owner_id');
            $t->string('title')->nullable();
            $t->json('settings')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('map_collaborators', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('map_id'); $t->integer('user_id'); $t->integer('added_by')->nullable();
            $t->string('role'); $t->string('status'); $t->string('invitation_token')->nullable();
            $t->timestamp('invited_at')->nullable(); $t->timestamp('accepted_at')->nullable(); $t->timestamps();
        });
        // MapPolicy::roleFor leest ook de missie-toegang tot een kaart.
        Schema::create('missions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->integer('owner_id');
            $t->string('name')->nullable();
            $t->string('map_ref_id')->nullable();
            $t->string('status')->default('planning');
            $t->uuid('linked_team_id')->nullable();
            $t->json('map')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->id(); $t->uuid('team_id'); $t->integer('user_id'); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('mission_id'); $t->integer('user_id');
            $t->string('role')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('route_maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('connect_organization_id')->nullable();
            $t->uuid('map_id');
            $t->uuid('mission_id')->nullable();
            $t->string('owner_id');
            $t->string('title')->nullable();
            $t->string('color')->nullable();
            $t->float('declination')->nullable();
            $t->text('equipment')->nullable();
            $t->float('speed')->nullable();
            $t->string('ic')->nullable();
            $t->string('cs')->nullable();
            $t->json('locations')->nullable();
            $t->json('meta')->nullable();
            $t->integer('pause_time')->default(0);
            $t->integer('total_time')->default(0);
            $t->float('total_distance')->default(0);
            $t->float('total_elevation')->default(0);
            $t->string('callsign')->nullable();
            $t->string('eenheid')->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->string('cloned_from_type', 16)->nullable();
            $t->string('cloned_from_id', 36)->nullable();
            $t->unsignedBigInteger('cloned_from_user_id')->nullable();
            \Tests\Support\RouteMapDiscoveryColumns::add($t);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('map_id')->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->string('type', 16)->default('walk');
            $t->string('title')->nullable();
            $t->text('notes')->nullable();
            $t->text('notes_ciphertext')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('distance_m')->default(0);
            $t->unsignedInteger('moving_time_s')->default(0);
            $t->unsignedInteger('elapsed_time_s')->default(0);
            $t->unsignedInteger('elevation_gain_m')->default(0);
            $t->float('avg_pace_s_per_km')->nullable();
            $t->float('avg_speed_kmh')->nullable();
            $t->float('avg_power_w')->nullable();
            $t->unsignedInteger('calories')->nullable();
            $t->text('points')->nullable();
            $t->text('points_ciphertext')->nullable();
            $t->double('start_lat')->nullable();
            $t->double('start_lon')->nullable();
            $t->string('source')->nullable();
            $t->string('garmin_activity_id')->nullable();
            $t->string('garmin_device_name')->nullable();
            $t->timestamps();
        });
        Schema::create('public_route_comments', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 10); $t->string('subject_id', 64);
            $t->unsignedBigInteger('user_id'); $t->string('body', 500);
            $t->json('mentions')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('public_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->string('handle', 30)->unique();
            $t->string('display_name', 60)->nullable();
            $t->string('bio', 300)->nullable();
            $t->boolean('enabled')->default(false);
            $t->boolean('hide_endpoints')->default(true);
            $t->timestamps();
        });

        foreach ([self::OWNER => 'eigenaar@example.test', self::OTHER => 'ander@example.test'] as $id => $email) {
            DB::table('users')->insert([
                'id' => $id, 'email' => $email, 'first_name' => 'Geheim', 'last_name' => 'Achternaam',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function loginAs(int $id): void
    {
        $user = new User();
        $user->id = $id;
        $user->trial_ends_at = now()->addDay();
        $this->actingAs($user, 'sanctum');
    }

    private function profile(int $userId, string $handle, array $extra = []): PublicProfile
    {
        return PublicProfile::create(array_merge(['user_id' => $userId, 'handle' => $handle, 'enabled' => true], $extra));
    }

    private function map(int $owner, string $title = 'Kaart'): string
    {
        $id = (string) Str::uuid();
        DB::table('maps')->insert(['id' => $id, 'owner_id' => (string) $owner, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function route(int $owner, string $mapId, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('route_maps')->insert($extra + [
            'id' => $id, 'map_id' => $mapId, 'owner_id' => (string) $owner, 'title' => 'Ronde Maashorst',
            'color' => '#ff0000', 'speed' => 5, 'total_distance' => 1500, 'total_elevation' => 12, 'total_time' => 900,
            'ic' => 'GEHEIM-IC', 'cs' => 'GEHEIM-CS', 'callsign' => 'Alpha-6', 'eenheid' => '11 Lichte Brigade',
            'equipment' => 'privé uitrusting',
            'locations' => json_encode([
                ['id' => 1, 'no' => 1, 'lat' => 51.70, 'lon' => 5.70, 'mgrs' => '31UFT1234', 'elevation' => 10, 'title' => 'Start',
                    'checkpoint' => ['enabled' => true, 'label' => 'CP1'], 'pause' => ['type' => null, 'custom' => 0],
                    'secret_note' => 'mijn pincode is 1234', 'images' => ['/storage/privé.jpg'], 'via' => [['lon' => 5.705, 'lat' => 51.702]]],
                ['id' => 2, 'no' => 2, 'lat' => 51.71, 'lon' => 5.71, 'title' => 'Einde'],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Rechte track van ~3 km (punt per ~50 m), noord-zuid. */
    private function activity(int $userId, array $extra = [], int $steps = 60): Activity
    {
        $points = [];
        for ($i = 0; $i <= $steps; $i++) {
            $points[] = ['lat' => 52.0 + $i * 0.00045, 'lon' => 5.0, 't' => $i * 30, 'ele' => 10 + $i, 'speed' => 1.4];
        }

        return Activity::create($extra + [
            'user_id' => $userId, 'type' => 'walk', 'title' => 'Ochtendwandeling',
            'notes' => 'privé notitie', 'started_at' => now()->subDay(),
            'distance_m' => 3000, 'moving_time_s' => 2100, 'elapsed_time_s' => 2200, 'elevation_gain_m' => 40,
            'avg_speed_kmh' => 5.1, 'points' => $points,
        ]);
    }

    // ── profielinstellingen ──────────────────────────────────────────────

    public function test_profile_is_off_by_default_and_needs_a_handle_to_enable(): void
    {
        $this->loginAs(self::OWNER);

        $this->getJson('/api/v1/user/public-profile')->assertOk()->assertJsonPath('profile', null);

        $this->putJson('/api/v1/user/public-profile', ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('handle');

        $this->putJson('/api/v1/user/public-profile', ['enabled' => true, 'handle' => 'Pad_Vinder', 'bio' => 'Wandelaar'])
            ->assertOk()
            ->assertJsonPath('profile.handle', 'pad_vinder') // altijd kleine letters
            ->assertJsonPath('profile.enabled', true)
            ->assertJsonPath('profile.hide_endpoints', true);
    }

    public function test_handle_must_be_valid_unique_and_not_reserved(): void
    {
        $this->profile(self::OTHER, 'bezet');
        $this->loginAs(self::OWNER);

        foreach (['ab', 'met spatie', 'punt.punt', str_repeat('a', 31), 'admin', 'bezet'] as $bad) {
            $this->putJson('/api/v1/user/public-profile', ['enabled' => true, 'handle' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('handle');
        }
    }

    public function test_disabled_or_unknown_profile_is_404_for_everyone(): void
    {
        $this->profile(self::OWNER, 'verborgen', ['enabled' => false]);

        $this->getJson('/api/v1/profiles/verborgen')->assertNotFound();
        $this->getJson('/api/v1/profiles/bestaatniet')->assertNotFound();
    }

    // ── publiceren ───────────────────────────────────────────────────────

    public function test_publishing_requires_an_enabled_public_profile(): void
    {
        $mapId = $this->map(self::OWNER);
        $routeId = $this->route(self::OWNER, $mapId);
        $this->loginAs(self::OWNER);

        $this->putJson("/api/v1/routemaps/$routeId/visibility", ['is_public' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'public_profile_required');

        $this->profile(self::OWNER, 'wandelaar');
        $this->putJson("/api/v1/routemaps/$routeId/visibility", ['is_public' => true])
            ->assertOk()->assertJsonPath('is_public', true)->assertJsonPath('profile_handle', 'wandelaar');

        // Intrekken kan altijd.
        $this->putJson("/api/v1/routemaps/$routeId/visibility", ['is_public' => false])
            ->assertOk()->assertJsonPath('is_public', false);
    }

    public function test_only_the_owner_can_publish_a_route_or_track(): void
    {
        $this->profile(self::OTHER, 'indringer');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER));
        $activity = $this->activity(self::OWNER);

        $this->loginAs(self::OTHER);
        $this->putJson("/api/v1/routemaps/$routeId/visibility", ['is_public' => true])->assertForbidden();
        $this->putJson("/api/v1/activities/{$activity->id}/visibility", ['is_public' => true])->assertNotFound();

        $this->assertFalse((bool) DB::table('route_maps')->where('id', $routeId)->value('is_public'));
        $this->assertFalse((bool) Activity::find($activity->id)->is_public);
    }

    public function test_organisation_routes_cannot_be_published(): void
    {
        $this->profile(self::OWNER, 'zakelijk');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER), ['connect_organization_id' => 5]);

        $this->loginAs(self::OWNER);
        $this->putJson("/api/v1/routemaps/$routeId/visibility", ['is_public' => true])->assertForbidden();
    }

    // ── wat een vreemde ziet ─────────────────────────────────────────────

    public function test_guest_sees_only_published_items_and_no_private_fields(): void
    {
        $this->profile(self::OWNER, 'wandelaar', ['bio' => 'Hoi']);
        $mapId = $this->map(self::OWNER);
        $public = $this->route(self::OWNER, $mapId, ['is_public' => true, 'published_at' => now()]);
        $private = $this->route(self::OWNER, $mapId, ['title' => 'Privé ronde']);
        $publicTrack = $this->activity(self::OWNER, ['is_public' => true, 'published_at' => now()]);
        $privateTrack = $this->activity(self::OWNER, ['title' => 'Privé track']);

        $res = $this->getJson('/api/v1/profiles/wandelaar')->assertOk();

        $res->assertJsonCount(1, 'routes')->assertJsonCount(1, 'tracks')
            ->assertJsonPath('routes.0.id', $public)
            ->assertJsonPath('tracks.0.id', $publicTrack->id)
            ->assertJsonPath('is_me', false)
            // Zonder weergavenaam tonen we de handle — nooit de echte naam.
            ->assertJsonPath('profile.name', 'wandelaar');

        $body = $res->getContent();
        foreach (['eigenaar@example.test', 'Geheim', 'Achternaam', 'GEHEIM-IC', 'Alpha-6', '11 Lichte Brigade', 'privé uitrusting',
                  'Privé ronde', 'Privé track', 'privé notitie', 'user_id', 'owner_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "'$secret' lekt naar de openbare lijst");
        }
        $this->assertNotContains($private, array_column($res->json('routes'), 'id'));
        $this->assertNotContains($privateTrack->id, array_column($res->json('tracks'), 'id'));
    }

    public function test_profile_has_totals_and_a_newest_first_timeline_without_timestamps(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $mapId = $this->map(self::OWNER);
        $this->route(self::OWNER, $mapId, ['is_public' => true, 'published_at' => now()->subDays(5)]);
        $this->route(self::OWNER, $mapId, ['title' => 'Privé ronde']);
        $newest = $this->activity(self::OWNER, ['is_public' => true, 'published_at' => now(), 'started_at' => now()->subDay()]);
        $this->activity(self::OWNER, ['title' => 'Privé track']);

        $res = $this->getJson('/api/v1/profiles/wandelaar')->assertOk();

        $res->assertJsonPath('stats.routes', 1)->assertJsonPath('stats.tracks', 1)
            ->assertJsonPath('stats.distance_m', 4500)
            ->assertJsonPath('stats.elevation_m', 52)
            ->assertJsonPath('stats.moving_time_s', 2100)
            ->assertJsonCount(2, 'timeline')
            ->assertJsonPath('timeline.0.kind', 'track')
            ->assertJsonPath('timeline.0.id', $newest->id)
            ->assertJsonPath('timeline.1.kind', 'route');
        foreach ($res->json('timeline') as $item) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $item['date']);
        }
        $this->assertStringNotContainsString('Privé', $res->getContent());
    }

    public function test_comments_need_a_public_profile_and_tags_resolve_to_profiles(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $this->profile(self::OTHER, 'buddy_1');
        $mapId = $this->map(self::OWNER);
        $route = $this->route(self::OWNER, $mapId, ['is_public' => true, 'published_at' => now()]);
        $url = "/api/v1/profiles/wandelaar/routes/{$route}/comments";

        $this->getJson($url)->assertOk()->assertJsonCount(0, 'comments');
        $this->postJson($url, ['body' => 'hoi'])->assertStatus(401);

        // Zonder eigen openbaar profiel mag je niet reageren.
        $this->loginAs(99);
        $this->postJson($url, ['body' => 'hoi'])->assertStatus(403);

        $this->loginAs(self::OTHER);
        $this->postJson($url, ['body' => 'Mooi! @wandelaar en @bestaatniet'])->assertCreated()
            ->assertJsonPath('comment.handle', 'buddy_1')->assertJsonPath('comment.mine', true);

        $res = $this->getJson($url)->assertOk()->assertJsonCount(1, 'comments');
        $this->assertStringNotContainsString('eigenaar@example.test', $res->getContent());
        $this->assertSame([10], \App\Models\PublicRouteComment::first()->mentions);

        // Alleen de schrijver of de maker verwijdert; een ander niet.
        $id = $res->json('comments.0.id');
        $this->loginAs(99);
        $this->deleteJson("$url/$id")->assertStatus(403);
        $this->loginAs(self::OWNER);
        $this->deleteJson("$url/$id")->assertOk();
        $this->getJson($url)->assertJsonCount(0, 'comments');
    }

    public function test_route_detail_is_whitelisted(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER), ['is_public' => true]);

        $res = $this->getJson("/api/v1/profiles/wandelaar/routes/$routeId")->assertOk();
        $res->assertJsonPath('route.locations.0.title', 'Start')
            ->assertJsonPath('route.locations.0.checkpoint.label', 'CP1')
            ->assertJsonPath('route.locations.0.via.0.lat', 51.702);

        $body = $res->getContent();
        foreach (['mijn pincode', 'privé.jpg', 'GEHEIM-IC', 'GEHEIM-CS', 'Alpha-6', 'eigenaar@example.test', 'owner_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_unpublished_route_detail_is_404_even_for_the_owner_url(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER));

        $this->getJson("/api/v1/profiles/wandelaar/routes/$routeId")->assertNotFound();
        // En niet te raden via het profiel van iemand anders.
        $this->profile(self::OTHER, 'ander');
        $this->getJson("/api/v1/profiles/ander/routes/$routeId")->assertNotFound();
    }

    public function test_track_endpoints_are_trimmed_so_a_home_start_is_not_exposed(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $a = $this->activity(self::OWNER, ['is_public' => true]);
        $all = $a->points;

        $track = $this->getJson("/api/v1/profiles/wandelaar/tracks/{$a->id}")->assertOk()
            // Geen tijdstip van de dag: alleen de datum, en `t` relatief.
            ->assertJsonMissingPath('track.started_at')
            ->assertJsonPath('track.started_on', $a->started_at->toDateString())
            ->json('track');
        $points = $track['points'];
        $this->assertEquals(0, $points[0]['t']);
        $this->assertGreaterThan(0, end($points)['t']);

        $this->assertLessThan(count($all), count($points));
        $first = $points[0];
        $last = end($points);
        $this->assertGreaterThanOrEqual(200, \App\Support\PublicRoutePresenter::haversine($all[0]['lat'], $all[0]['lon'], $first['lat'], $first['lon']));
        $this->assertGreaterThanOrEqual(200, \App\Support\PublicRoutePresenter::haversine(end($all)['lat'], end($all)['lon'], $last['lat'], $last['lon']));

        // Zonder knippen (bewuste keuze van de eigenaar) komt alles mee.
        PublicProfile::where('user_id', self::OWNER)->update(['hide_endpoints' => false]);
        $this->assertCount(count($all), $this->getJson("/api/v1/profiles/wandelaar/tracks/{$a->id}")->json('track.points'));
    }

    public function test_a_track_too_short_to_trim_is_not_shown_at_all(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $short = $this->activity(self::OWNER, ['is_public' => true], steps: 6); // ~270 m

        $this->getJson('/api/v1/profiles/wandelaar')->assertOk()->assertJsonCount(0, 'tracks');
        $this->getJson("/api/v1/profiles/wandelaar/tracks/{$short->id}")->assertNotFound();
    }

    // ── gebruiken (klonen) ───────────────────────────────────────────────

    public function test_guest_cannot_clone(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER), ['is_public' => true]);

        $this->postJson("/api/v1/profiles/wandelaar/routes/$routeId/clone")->assertUnauthorized();
    }

    public function test_cloning_a_route_creates_an_independent_copy_in_a_new_map(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER), ['is_public' => true]);

        $this->loginAs(self::OTHER);
        $res = $this->postJson("/api/v1/profiles/wandelaar/routes/$routeId/clone")->assertCreated();

        $copy = DB::table('route_maps')->where('id', $res->json('routemap.id'))->first();
        $this->assertSame((string) self::OTHER, (string) $copy->owner_id);
        $this->assertNotSame($routeId, $copy->id);
        $this->assertSame('route_map', $copy->cloned_from_type);
        $this->assertSame($routeId, $copy->cloned_from_id);
        $this->assertSame(self::OWNER, (int) $copy->cloned_from_user_id);
        $this->assertFalse((bool) $copy->is_public, 'een kopie is nooit meteen openbaar');

        // Privévelden van de bron zijn niet meegekomen.
        $this->assertNull($copy->ic);
        $this->assertNull($copy->callsign);
        $this->assertNull($copy->eenheid);
        $this->assertNull($copy->equipment);
        $this->assertStringNotContainsString('pincode', $copy->locations);
        $this->assertStringNotContainsString('privé.jpg', $copy->locations);

        // Nieuwe kaart van de kloner; bron onaangeroerd.
        $this->assertSame((string) self::OTHER, (string) DB::table('maps')->where('id', $res->json('map.id'))->value('owner_id'));
        $this->assertSame((string) self::OWNER, (string) DB::table('route_maps')->where('id', $routeId)->value('owner_id'));

        // De teller telt verschillende mensen die de route gebruikten.
        $this->getJson('/api/v1/profiles/wandelaar')->assertJsonPath('routes.0.clones', 1);
    }

    public function test_cloning_into_an_existing_map_requires_edit_access_to_it(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER), ['is_public' => true]);
        $vreemdeKaart = $this->map(self::OWNER, 'Niet van jou');
        $eigenKaart = $this->map(self::OTHER, 'Van mij');

        $this->loginAs(self::OTHER);
        $this->postJson("/api/v1/profiles/wandelaar/routes/$routeId/clone", ['map_id' => $vreemdeKaart])->assertForbidden();

        $res = $this->postJson("/api/v1/profiles/wandelaar/routes/$routeId/clone", ['map_id' => $eigenKaart, 'title' => 'Mijn variant'])->assertCreated();
        $this->assertSame($eigenKaart, $res->json('map.id'));
        $this->assertSame('Mijn variant', DB::table('route_maps')->where('id', $res->json('routemap.id'))->value('title'));
    }

    public function test_cannot_clone_an_unpublished_route(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $routeId = $this->route(self::OWNER, $this->map(self::OWNER));

        $this->loginAs(self::OTHER);
        $this->postJson("/api/v1/profiles/wandelaar/routes/$routeId/clone")->assertNotFound();
        $this->assertSame(0, DB::table('route_maps')->where('owner_id', (string) self::OTHER)->count());
    }

    public function test_cloning_a_track_builds_a_route_from_the_waypoints_the_app_sends(): void
    {
        $this->profile(self::OWNER, 'wandelaar');
        $a = $this->activity(self::OWNER, ['is_public' => true]);

        $this->loginAs(self::OTHER);
        $locs = [
            ['lat' => 52.010, 'lon' => 5.0, 'mgrs' => '31UFT0000', 'elevation' => 20, 'title' => ''],
            ['lat' => 52.020, 'lon' => 5.0],
        ];
        $res = $this->postJson("/api/v1/profiles/wandelaar/tracks/{$a->id}/clone", ['locations' => $locs, 'title' => 'Wandeling'])
            ->assertCreated();

        $copy = DB::table('route_maps')->where('id', $res->json('routemap.id'))->first();
        $this->assertSame('activity', $copy->cloned_from_type);
        $this->assertSame((string) $a->id, $copy->cloned_from_id);
        $this->assertCount(2, json_decode($copy->locations, true));
        // 0,01° breedte ≈ 1112 m — door de server zelf berekend.
        $this->assertEqualsWithDelta(1112, $copy->total_distance, 5);
        $this->assertEqualsWithDelta(40, $copy->total_elevation, 0.01);
        $this->assertEqualsWithDelta(5.1, $copy->speed, 0.01);

        $this->postJson("/api/v1/profiles/wandelaar/tracks/{$a->id}/clone", ['locations' => [$locs[0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('locations');
    }

    // ── zoeken ───────────────────────────────────────────────────────────

    public function test_search_finds_only_enabled_profiles_and_not_yourself(): void
    {
        $this->profile(self::OTHER, 'bergbeklimmer', ['display_name' => 'Bram']);
        $this->profile(self::OWNER, 'bergwandelaar');
        DB::table('users')->insert(['id' => 30, 'email' => 'derde@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->profile(30, 'bergverborgen', ['enabled' => false]);

        $this->loginAs(self::OWNER);
        $handles = array_column($this->getJson('/api/v1/profiles?q=berg')->assertOk()->json('profiles'), 'handle');
        $this->assertSame(['bergbeklimmer'], $handles);

        $this->getJson('/api/v1/profiles?q=Bram')->assertJsonPath('profiles.0.handle', 'bergbeklimmer');
        // Een underscore in de zoekterm is letterlijk (geen LIKE-joker), ook op SQLite.
        $this->profile(40, 'pad_vinder');
        $this->profile(41, 'padxvinder');
        $this->assertSame(['pad_vinder'], array_column($this->getJson('/api/v1/profiles?q=pad_v')->json('profiles'), 'handle'));
        $this->getJson('/api/v1/profiles?q=b')->assertJsonCount(0, 'profiles');
        // Wildcards worden niet als patroon uitgevoerd.
        $this->getJson('/api/v1/profiles?q=%25%25')->assertJsonCount(0, 'profiles');
    }
}
