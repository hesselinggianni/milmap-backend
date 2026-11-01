<?php

namespace Tests\Feature;

use App\Models\KakoAppCheckin;
use App\Models\KakoAppParticipant;
use App\Models\KakoAppPosition;
use App\Models\Map;
use App\Models\RouteAssignment;
use App\Models\RouteAssignmentCollaborator;
use App\Models\RouteMap;
use App\Models\User;
use App\Support\KakoAppRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * KAKO app-modus (docs/kako-app-modus.md): uitnodigen, toestemming, check-ins, positie-samples,
 * autorisatie (deelnemer ≠ beheerder) en de bewaartermijn.
 */
class KakoAppModeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $jansen;
    private User $stranger;
    private RouteAssignment $a;

    // Start op de Oirschotse Heide; post p1 ligt ±900 m verderop.
    private const START = [51.544, 5.28];
    private const P1 = [51.55, 5.29];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('Olaf', 'Eigenaar');
        $this->jansen = $this->user('Jan', 'Jansen');
        $this->stranger = $this->user('Sam', 'Vreemd');
        $this->a = $this->assignment(['mode' => 'both']);
    }

    private function user(string $first, string $last): User
    {
        return User::create(['first_name' => $first, 'last_name' => $last, 'email' => Str::uuid() . '@example.test', 'password' => bcrypt('Password123!'), 'email_verified_at' => now()]);
    }

    private function as(User $user): self
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function assignment(array $settings = [], array $extra = []): RouteAssignment
    {
        $map = Map::create(['owner_id' => $this->owner->id, 'title' => 'Oefenterrein', 'status' => 'active']);
        $a = RouteAssignment::create($extra + [
            'id' => (string) Str::uuid(), 'map_id' => $map->id, 'owner_id' => $this->owner->id, 'title' => 'KAKO test', 'type' => 'training',
            'settings' => $settings + [
                'status' => 'draft', 'emergency' => 'Bel 112',
                'posts' => [['id' => 'start', 'label' => 'Start', 'lat' => self::START[0], 'lon' => self::START[1]], ['id' => 'p1', 'label' => 'Post 1', 'lat' => self::P1[0], 'lon' => self::P1[1]]],
            ],
            'participants' => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => '1A'], ['id' => 'p2', 'name' => 'De Vries', 'route_code' => '2B']],
        ]);
        foreach (['1' => 'course', 'A' => 'paths'] as $code => $kind) {
            RouteMap::create([
                'id' => (string) Str::uuid(), 'map_id' => $map->id, 'owner_id' => $this->owner->id, 'route_assignment_id' => $a->id,
                'route_code' => $code, 'title' => "KAKO · {$code}", 'speed' => 5, 'meta' => ['kind' => $kind],
                'total_distance' => 1800, 'total_time' => 1000, 'pause_time' => 0, 'total_elevation' => 0,
                'locations' => [
                    ['id' => 'start', 'no' => 1, 'lat' => self::START[0], 'lon' => self::START[1], 'title' => 'Start', 'checkpoint' => ['enabled' => true, 'label' => 'Start']],
                    ['id' => 'p1', 'no' => 2, 'lat' => self::P1[0], 'lon' => self::P1[1], 'title' => 'Post 1', 'description' => 'NO van Y-splitsing', 'checkpoint' => ['enabled' => true, 'label' => 'Post 1']],
                    ['id' => 'start', 'no' => 3, 'lat' => self::START[0], 'lon' => self::START[1], 'title' => 'Finish', 'checkpoint' => ['enabled' => true, 'label' => 'Finish']],
                ],
            ]);
        }

        return $a;
    }

    /** Eigenaar nodigt Jansen uit, Jansen sluit aan met de code; geeft de rij terug. */
    private function joined(?RouteAssignment $a = null): KakoAppParticipant
    {
        $a ??= $this->a;
        $this->as($this->owner)->postJson("/api/v1/route-assignments/{$a->id}/participants/invites", ['participant_keys' => ['p1']])->assertCreated();
        $code = KakoAppParticipant::where('route_assignment_id', $a->id)->where('participant_key', 'p1')->value('invite_code');
        $this->as($this->jansen)->postJson('/api/v1/kako/join', ['code' => $code])->assertCreated();

        return KakoAppParticipant::where('route_assignment_id', $a->id)->where('participant_key', 'p1')->firstOrFail();
    }

    private function openWindow(?RouteAssignment $a = null, int $fromMin = -10, int $untilMin = 120): void
    {
        $a ??= $this->a;
        $this->as($this->owner)->putJson("/api/v1/route-assignments/{$a->id}/app-mode/window", [
            'from' => now()->addMinutes($fromMin)->toIso8601String(), 'until' => now()->addMinutes($untilMin)->toIso8601String(), 'participant_keys' => ['p1'],
        ])->assertOk();
    }

    private function consent(?RouteAssignment $a = null): void
    {
        $a ??= $this->a;
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$a->id}/participation/consent", ['tracking' => true, 'version' => KakoAppRules::CONSENT_VERSION])->assertOk();
    }

    private function checkin(array $over = [], ?RouteAssignment $a = null)
    {
        $a ??= $this->a;

        return $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$a->id}/checkins", $over + [
            'client_uuid' => (string) Str::uuid(), 'post_id' => 'p1', 'role' => 'post', 'method' => 'manual',
            'lat' => self::P1[0], 'lon' => self::P1[1], 'accuracy' => 5,
        ]);
    }

    // ── Uitnodigen en aansluiten ───────────────────────────────────────

    public function test_invites_require_app_or_both_mode(): void
    {
        $paper = $this->assignment(['mode' => 'paper']);
        $this->as($this->owner)->postJson("/api/v1/route-assignments/{$paper->id}/participants/invites")->assertStatus(422);
    }

    public function test_join_flow_binds_account_and_code_is_single_use(): void
    {
        $this->as($this->owner)->postJson("/api/v1/route-assignments/{$this->a->id}/participants/invites")
            ->assertCreated()->assertJsonPath('created', 2)->assertJsonPath('participants.0.invite.code', fn ($c) => strlen($c) === 10);
        $code = KakoAppParticipant::where('participant_key', 'p1')->value('invite_code');

        $this->as($this->jansen)->getJson("/api/v1/kako/join/{$code}")->assertOk()->assertJsonPath('name', 'Jansen')->assertJsonPath('title', 'KAKO test');
        $this->as($this->jansen)->postJson('/api/v1/kako/join', ['code' => strtolower(substr($code, 0, 5) . '-' . substr($code, 5))])
            ->assertCreated()->assertJsonPath('status', 'accepted')->assertJsonPath('name', 'Jansen')->assertJsonPath('seen_by.0', 'Olaf Eigenaar');

        // Eenmalig, en dezelfde melding als bij een onbekende code (geen orakel).
        $this->as($this->stranger)->postJson('/api/v1/kako/join', ['code' => $code])->assertStatus(422);
        $this->as($this->stranger)->postJson('/api/v1/kako/join', ['code' => 'NOSUCHCODE'])->assertStatus(422);
        $this->assertNull(KakoAppParticipant::where('participant_key', 'p1')->value('invite_code'));
    }

    public function test_expired_code_is_refused_and_second_slot_for_same_account_is_conflict(): void
    {
        $this->as($this->owner)->postJson("/api/v1/route-assignments/{$this->a->id}/participants/invites")->assertCreated();
        KakoAppParticipant::where('participant_key', 'p2')->update(['invite_expires_at' => now()->subMinute()]);
        $expired = KakoAppParticipant::where('participant_key', 'p2')->value('invite_code');
        $this->as($this->jansen)->postJson('/api/v1/kako/join', ['code' => $expired])->assertStatus(422);

        $code1 = KakoAppParticipant::where('participant_key', 'p1')->value('invite_code');
        $this->as($this->jansen)->postJson('/api/v1/kako/join', ['code' => $code1])->assertCreated();
        KakoAppParticipant::where('participant_key', 'p2')->update(['invite_expires_at' => now()->addDay()]);
        $this->as($this->jansen)->postJson('/api/v1/kako/join', ['code' => $expired])->assertStatus(409);
    }

    // ── Autorisatie ────────────────────────────────────────────────────

    public function test_participant_cannot_read_the_assignment_or_manager_endpoints(): void
    {
        $this->joined();
        $id = $this->a->id;
        $this->as($this->jansen)->getJson("/api/v1/route-assignments/{$id}")->assertForbidden();
        foreach (['participants', 'live', 'checkins', 'checkins/summary', 'participant-events', 'tag-tokens', 'positions?participant=p1'] as $path) {
            $this->as($this->jansen)->getJson("/api/v1/route-assignments/{$id}/{$path}")->assertForbidden();
        }
        $this->as($this->jansen)->putJson("/api/v1/route-assignments/{$id}/app-mode/window", ['from' => now()->toIso8601String(), 'until' => now()->addHour()->toIso8601String()])->assertForbidden();
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$id}/participants/invites")->assertForbidden();
    }

    public function test_stranger_has_no_participation_and_viewer_collaborator_sees_no_participant_data(): void
    {
        $this->joined();
        $id = $this->a->id;
        $this->as($this->stranger)->getJson("/api/v1/route-assignments/{$id}/participation")->assertNotFound();
        $this->as($this->stranger)->postJson("/api/v1/route-assignments/{$id}/checkins", ['client_uuid' => 'x1', 'post_id' => 'p1'])->assertNotFound();
        $this->as($this->stranger)->postJson("/api/v1/route-assignments/{$id}/positions", ['samples' => [['t' => now()->toIso8601String(), 'lat' => 51.5, 'lon' => 5.3]]])->assertNotFound();

        RouteAssignmentCollaborator::create(['route_assignment_id' => $id, 'user_id' => $this->stranger->id, 'role' => 'viewer', 'status' => 'accepted']);
        $this->as($this->stranger)->getJson("/api/v1/route-assignments/{$id}/live")->assertForbidden();
        $this->as($this->stranger)->getJson("/api/v1/route-assignments/{$id}/participants")->assertForbidden();

        // Een geaccepteerde, expliciete editor mag wel; een nog niet geaccepteerde niet.
        RouteAssignmentCollaborator::where('user_id', $this->stranger->id)->update(['role' => 'editor', 'status' => 'pending']);
        $this->as($this->stranger)->getJson("/api/v1/route-assignments/{$id}/participants")->assertForbidden();
        RouteAssignmentCollaborator::where('user_id', $this->stranger->id)->update(['status' => 'accepted']);
        $this->as($this->stranger)->getJson("/api/v1/route-assignments/{$id}/participants")->assertOk();
    }

    public function test_participation_payload_has_own_route_but_no_post_coordinates(): void
    {
        $this->joined();
        $res = $this->as($this->jansen)->getJson("/api/v1/route-assignments/{$this->a->id}/participation")->assertOk()
            ->assertJsonPath('route.code', '1A')
            ->assertJsonPath('route.parts.0.kind', 'course')->assertJsonPath('route.parts.1.kind', 'paths')
            ->assertJsonPath('route.parts.0.legs.0.label', 'Post 1')
            ->assertJsonPath('route.parts.0.legs.0.description', 'NO van Y-splitsing')
            ->assertJsonPath('route.parts.0.legs.1.label', 'Wissel')
            ->assertJsonPath('route.parts.1.legs.1.role', 'finish')
            ->assertJsonPath('restrictions.navigation', true);
        $this->assertNull($res->json('route.parts.1.legs.0.bearing_deg'), 'padenroute heeft geen koers');
        $this->assertIsInt($res->json('route.parts.0.legs.0.bearing_deg'));
        $json = $res->getContent();
        foreach (['"lat"', '"lon"', '51.55', 'De Vries', '"2B"', 'prikker'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} hoort niet in de deelnemer-payload");
        }
    }

    public function test_preview_shows_the_participant_view_to_the_manager_only_and_follows_the_current_route_code(): void
    {
        $id = $this->a->id;
        $preview = fn (string $key = 'p1') => $this->as($this->owner)->getJson("/api/v1/route-assignments/{$id}/participants/{$key}/preview");

        // Ook voor iemand die nog niet is aangesloten: precies wat de deelnemer straks ziet, zonder coördinaten.
        $res = $preview()->assertOk()->assertJsonPath('preview', true)->assertJsonPath('joined', false)->assertJsonPath('name', 'Jansen')
            ->assertJsonPath('route.code', '1A')->assertJsonPath('route.parts.0.kind', 'course')->assertJsonPath('route.parts.0.legs.0.label', 'Post 1');
        foreach (['"lat"', '"lon"', '51.55', 'De Vries'] as $secret) {
            $this->assertStringNotContainsString($secret, $res->getContent());
        }
        $preview('nope')->assertNotFound();
        $this->assertSame(0, KakoAppParticipant::count(), 'meekijken maakt of wijzigt niets');

        $this->joined();
        $this->as($this->jansen)->getJson("/api/v1/route-assignments/{$id}/participants/p1/preview")->assertForbidden();
        $preview()->assertJsonPath('joined', true);

        // De route kan ook in productie wisselen (andere route_code): de preview volgt de HUIDIGE code.
        $people = fn (string $code) => [['id' => 'p1', 'name' => 'Jansen', 'route_code' => $code], ['id' => 'p2', 'name' => 'De Vries', 'route_code' => '2B']];
        $this->a->update(['participants' => $people('A1')]);
        $preview()->assertJsonPath('route.code', 'A1')->assertJsonPath('route.parts.0.kind', 'paths')->assertJsonPath('route.parts.1.kind', 'course');

        // Een check-in op een prikker van de OUDE route kan dan niet meer: not_on_route.
        $this->openWindow();
        $this->a->update(['participants' => $people('9Z')]);
        $preview()->assertJsonPath('route.parts.0.found', false);
        $this->checkin()->assertCreated()->assertJsonPath('checkin.result', 'not_on_route');
    }

    // ── Check-in ───────────────────────────────────────────────────────

    public function test_checkin_attempts_are_all_logged_and_first_ok_marks_visited(): void
    {
        $this->joined();
        $this->openWindow();

        $far = $this->checkin(['lat' => self::START[0], 'lon' => self::START[1]])->assertCreated();
        $far->assertJsonPath('checkin.result', 'too_far')->assertJsonPath('checkin.within_radius', false)->assertJsonPath('status.visited', false);
        $this->assertGreaterThan(500, $far->json('checkin.distance_m'));

        $this->checkin(['lat' => null, 'lon' => null])->assertCreated()->assertJsonPath('checkin.result', 'no_position');
        $ok = $this->checkin()->assertCreated();
        $ok->assertJsonPath('checkin.result', 'ok')->assertJsonPath('status.visited', true)->assertJsonPath('status.attempts', 3);
        $this->checkin()->assertCreated()->assertJsonPath('status.attempts', 4);

        $this->assertSame(4, KakoAppCheckin::count());
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/checkins")->assertOk()->assertJsonCount(4)
            ->assertJsonPath('0.participant_name', 'Jansen');
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/checkins/summary")->assertOk()
            ->assertJsonPath('participants.0.posts.0.visited', true)->assertJsonPath('participants.0.posts.0.attempts', 4);
        $this->as($this->jansen)->getJson("/api/v1/route-assignments/{$this->a->id}/participation")
            ->assertJsonPath('route.parts.0.legs.0.visited', true)->assertJsonPath('route.parts.0.legs.0.attempts', 4);
    }

    public function test_checkin_is_idempotent_on_client_uuid(): void
    {
        $this->joined();
        $this->openWindow();
        $this->checkin(['client_uuid' => 'same-1'])->assertCreated();
        $this->checkin(['client_uuid' => 'same-1'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, KakoAppCheckin::count());
    }

    public function test_checkin_outside_window_wrong_post_and_unset_window_are_logged_not_counted(): void
    {
        $this->joined();
        // Nog geen venster: gelogd, niet geteld.
        $this->checkin()->assertCreated()->assertJsonPath('checkin.result', 'outside_window')->assertJsonPath('status.visited', false);
        $this->openWindow();
        $this->checkin(['post_id' => 'nope'])->assertCreated()->assertJsonPath('checkin.result', 'not_on_route');
        // Een herhaling uit de outbox met een oude tijd van vóór het venster telt ook niet.
        $this->checkin(['fix_at' => now()->subHour()->toIso8601String()])->assertCreated()->assertJsonPath('checkin.result', 'outside_window');
        $this->assertSame(3, KakoAppCheckin::count());
    }

    public function test_a_checkin_after_the_cut_off_is_recognisable_as_late(): void
    {
        $this->joined();
        $this->openWindow(null, -120, 60);
        $this->checkin()->assertCreated()->assertJsonPath('checkin.result', 'ok')->assertJsonPath('checkin.late_s', null);

        // Het venster (de cut off tijd, incl. extra tijd) liep 10 minuten geleden af: de poging wordt gelogd als
        // outside_window, telt niet mee als bezocht, maar is als "te laat" herkenbaar — ook voor de instructeur.
        $this->as($this->owner)->putJson("/api/v1/route-assignments/{$this->a->id}/app-mode/window", [
            'from' => now()->subMinutes(120)->toIso8601String(), 'until' => now()->subMinutes(10)->toIso8601String(), 'participant_keys' => ['p1'],
        ])->assertOk();
        $late = $this->checkin(['post_id' => 'p1'])->assertCreated();
        $late->assertJsonPath('checkin.result', 'outside_window');
        $this->assertEqualsWithDelta(600, $late->json('checkin.late_s'), 5);
        $rows = $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/checkins")->assertOk();
        $this->assertEqualsWithDelta(600, collect($rows->json())->firstWhere('result', 'outside_window')['late_s'], 5);
        // De cut off is een momentopname: de eerdere (op tijd) poging blijft op tijd, ook nu het venster is ingekort.
        $this->assertNull(collect($rows->json())->firstWhere('result', 'ok')['late_s']);
        $this->as($this->owner)->putJson("/api/v1/route-assignments/{$this->a->id}/app-mode/window", [
            'from' => now()->subMinutes(120)->toIso8601String(), 'until' => now()->addMinutes(60)->toIso8601String(), 'participant_keys' => ['p1'],
        ])->assertOk();
        $again = $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/checkins")->assertOk();
        $this->assertEqualsWithDelta(600, collect($again->json())->firstWhere('result', 'outside_window')['late_s'], 5, 'verlengen herschrijft het log niet');
    }

    public function test_checkin_methods_tokens_and_attempt_limit(): void
    {
        $a = $this->assignment(['mode' => 'both', 'appMode' => ['checkin' => ['methods' => ['manual', 'qr'], 'maxAttempts' => 2]]]);
        $this->joined($a);
        $this->openWindow($a);

        $this->checkin(['method' => 'nfc', 'code' => 'x'], $a)->assertCreated()->assertJsonPath('checkin.result', 'method_not_allowed');
        $this->checkin(['method' => 'qr', 'code' => 'fout', 'lat' => null, 'lon' => null], $a)->assertCreated()->assertJsonPath('checkin.result', 'wrong_code');
        // Twee pogingen telden (wrong_code + deze): de derde wordt nog gelogd maar niet meer geteld.
        $this->checkin(['lat' => self::START[0], 'lon' => self::START[1]], $a)->assertCreated()->assertJsonPath('checkin.result', 'too_far');
        $this->checkin([], $a)->assertCreated()->assertJsonPath('checkin.result', 'attempts_exhausted');

        $b = $this->assignment(['mode' => 'both']);
        $this->joined($b);
        $this->openWindow($b);
        $token = $this->as($this->owner)->getJson("/api/v1/route-assignments/{$b->id}/tag-tokens")->assertOk()->json('posts.1.token');
        $this->checkin(['method' => 'qr', 'code' => $token, 'lat' => null, 'lon' => null], $b)->assertCreated()
            ->assertJsonPath('checkin.result', 'ok')->assertJsonPath('status.visited', true);
        // Een token van een andere prikker/opdracht werkt niet.
        $this->checkin(['method' => 'nfc', 'code' => KakoAppRules::tagToken($b->id, 'start', 1), 'client_uuid' => 'other'], $b)->assertCreated()->assertJsonPath('checkin.result', 'wrong_code');
    }

    public function test_record_mode_never_refuses_but_flags_distance(): void
    {
        $a = $this->assignment(['mode' => 'both', 'appMode' => ['checkin' => ['verification' => 'record']]]);
        $this->joined($a);
        $this->openWindow($a);
        $this->checkin(['lat' => self::START[0], 'lon' => self::START[1]], $a)->assertCreated()
            ->assertJsonPath('checkin.result', 'ok')->assertJsonPath('checkin.within_radius', false);
    }

    // ── Toestemming en positie-samples ─────────────────────────────────

    private function sample(int $minutesAgo = 1, array $over = []): array
    {
        return $over + ['t' => now()->subMinutes($minutesAgo)->toIso8601String(), 'lat' => self::P1[0], 'lon' => self::P1[1], 'accuracy' => 8, 'speed' => -1, 'heading' => -1];
    }

    public function test_positions_are_refused_without_consent_and_without_window(): void
    {
        $this->joined();
        $url = "/api/v1/route-assignments/{$this->a->id}/positions";
        $this->as($this->jansen)->postJson($url, ['samples' => [$this->sample()]])->assertOk()
            ->assertJsonPath('accepted', 0)->assertJsonPath('rejected', 1)->assertJsonPath('sharing.reason', 'no_consent');
        $this->consent();
        $this->as($this->jansen)->postJson($url, ['samples' => [$this->sample()]])->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('sharing.reason', 'not_started');
        $this->assertSame(0, KakoAppPosition::count());
    }

    public function test_positions_are_stored_inside_window_deduped_and_visible_live_to_manager_only(): void
    {
        $this->joined();
        $this->openWindow();
        $this->consent();
        $url = "/api/v1/route-assignments/{$this->a->id}/positions";

        $first = $this->sample(2);
        $res = $this->as($this->jansen)->postJson($url, ['samples' => [
            $first, $this->sample(1), $this->sample(30, ['t' => now()->subMinutes(30)->toIso8601String()]), $this->sample(0, ['t' => now()->addMinutes(5)->toIso8601String()]),
        ]])->assertOk();
        // Van vóór het venster (-30 min) en uit de toekomst: geweigerd.
        $res->assertJsonPath('accepted', 2)->assertJsonPath('rejected', 2)->assertJsonPath('sharing.active', true);
        $this->as($this->jansen)->postJson($url, ['samples' => [$first]])->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('duplicate', 1);
        $this->assertSame(2, KakoAppPosition::count());
        $this->assertNull(KakoAppPosition::first()->speed, 'speed -1 = onbekend');

        $live = $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/live")->assertOk();
        $live->assertJsonPath('participants.0.name', 'Jansen')->assertJsonPath('participants.0.sharing.active', true);
        $this->assertEqualsWithDelta(self::P1[0], $live->json('participants.0.last.lat'), 0.0001);
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/positions?participant=p1")->assertOk()->assertJsonPath('count', 2);
        // Wie meekeek, ziet de deelnemer terug in z'n eigen inzage.
        $mine = $this->as($this->jansen)->getJson("/api/v1/route-assignments/{$this->a->id}/participation/my-data")->assertOk();
        $this->assertContains('live_viewed', collect($mine->json('events'))->pluck('type')->all());
        $this->assertContains('track_viewed', collect($mine->json('events'))->pluck('type')->all());
        $this->assertCount(2, $mine->json('positions'));
    }

    public function test_withdrawing_consent_stops_sharing_immediately_and_can_wipe_data(): void
    {
        $this->joined();
        $this->openWindow();
        $this->consent();
        $url = "/api/v1/route-assignments/{$this->a->id}/positions";
        $this->as($this->jansen)->postJson($url, ['samples' => [$this->sample()]])->assertOk()->assertJsonPath('accepted', 1);

        $this->as($this->jansen)->deleteJson("/api/v1/route-assignments/{$this->a->id}/participation/consent")
            ->assertOk()->assertJsonPath('consent.given', false)->assertJsonPath('sharing.reason', 'withdrawn');
        $this->as($this->jansen)->postJson($url, ['samples' => [$this->sample(0)]])->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('sharing.reason', 'withdrawn');
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/live")->assertJsonPath('participants.0.last', null);
        // Eerder gedeelde posities blijven tot de bewaartermijn, tenzij de deelnemer wist.
        $this->assertSame(1, KakoAppPosition::count());
        $this->as($this->jansen)->deleteJson("/api/v1/route-assignments/{$this->a->id}/participation/positions")->assertOk()->assertJsonPath('deleted', 1);
        $this->assertSame(0, KakoAppPosition::count());
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/participant-events")
            ->assertJsonFragment(['type' => 'consent_withdrawn'])->assertJsonFragment(['type' => 'positions_deleted']);
    }

    public function test_declining_consent_after_sharing_clears_the_last_known_position(): void
    {
        $this->joined();
        $this->openWindow();
        $this->consent();
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$this->a->id}/positions", ['samples' => [$this->sample()]])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertNotNull(KakoAppParticipant::first()->last_lat);

        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$this->a->id}/participation/consent", ['tracking' => false, 'version' => KakoAppRules::CONSENT_VERSION])->assertOk();

        $this->assertNull(KakoAppParticipant::first()->last_lat);
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/live")->assertJsonPath('participants.0.last', null);
    }

    public function test_tracking_mode_off_and_required_and_old_consent_version(): void
    {
        $off = $this->assignment(['mode' => 'both', 'appMode' => ['tracking' => ['mode' => 'off']]]);
        $this->joined($off);
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$off->id}/participation/consent", ['tracking' => true, 'version' => KakoAppRules::CONSENT_VERSION])->assertStatus(422);

        $req = $this->assignment(['mode' => 'both', 'appMode' => ['tracking' => ['mode' => 'required']]]);
        $this->joined($req);
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$req->id}/participation/consent", ['tracking' => false, 'version' => KakoAppRules::CONSENT_VERSION])
            ->assertOk()->assertJsonPath('status', 'declined');

        // Declined = papier: geen check-ins of posities in de app, maar bedenken mag (toestemming herstelt de deelname).
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$req->id}/checkins", ['client_uuid' => 'd1', 'post_id' => 'p1'])->assertNotFound();
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$req->id}/participation/consent", ['tracking' => true, 'version' => KakoAppRules::CONSENT_VERSION])
            ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('consent.given', true);

        // Een verouderde tekstversie telt niet: eerst opnieuw lezen en bevestigen.
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$req->id}/participation/consent", ['tracking' => true, 'version' => 0])->assertStatus(422);
    }

    // ── Venster ────────────────────────────────────────────────────────

    public function test_window_is_capped_validated_and_can_be_ended(): void
    {
        $this->joined();
        $id = $this->a->id;
        $put = fn (array $b) => $this->as($this->owner)->putJson("/api/v1/route-assignments/{$id}/app-mode/window", $b);
        $put(['from' => now()->toIso8601String(), 'until' => now()->addHours(13)->toIso8601String()])->assertStatus(422);
        $put(['from' => now()->addHour()->toIso8601String(), 'until' => now()->toIso8601String()])->assertStatus(422);

        $this->openWindow();
        $this->assertTrue(KakoAppParticipant::where('participant_key', 'p1')->first()->inWindow());
        $this->as($this->owner)->postJson("/api/v1/route-assignments/{$id}/app-mode/end")->assertOk()->assertJsonPath('ended', 1);
        $this->assertFalse(KakoAppParticipant::where('participant_key', 'p1')->first()->inWindow());
        $this->as($this->jansen)->getJson('/api/v1/kako/participations')->assertOk()->assertJsonPath('0.ended', true);
    }

    // ── Afgeschermde modus ─────────────────────────────────────────────

    public function test_leaving_the_restricted_mode_is_logged_and_can_be_forbidden(): void
    {
        $this->joined();
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$this->a->id}/participation/mode", ['state' => 'left'])->assertOk()->assertJsonPath('mode_left', true);
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/participants")->assertJsonPath('participants.0.mode_left', true);

        $strict = $this->assignment(['mode' => 'both', 'appMode' => ['leaveAllowed' => false]]);
        $this->joined($strict);
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$strict->id}/participation/mode", ['state' => 'left'])->assertForbidden();
    }

    public function test_instructor_sees_when_a_participant_also_has_map_access(): void
    {
        $this->joined();
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/participants")->assertJsonPath('participants.0.has_map_access', false);
        $this->as($this->owner)->getJson("/api/v1/route-assignments/{$this->a->id}/participants")
            ->assertJsonPath('participants.0.checkins.posts_total', 3)->assertJsonPath('participants.1.status', 'none');
    }

    // ── Bewaartermijn en account verwijderen ───────────────────────────

    public function test_purge_command_deletes_positions_after_retention_and_keeps_checkin_result(): void
    {
        $p = $this->joined();
        $this->openWindow();
        $this->consent();
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$this->a->id}/positions", ['samples' => [$this->sample()]])->assertOk();
        $this->checkin()->assertCreated();

        // Venster eindigde 20 dagen geleden, termijn is 30: blijft staan.
        $p->update(['window_from' => now()->subDays(21), 'window_until' => now()->subDays(20)]);
        $this->artisan('kako:purge-positions')->assertSuccessful();
        $this->assertSame(1, KakoAppPosition::count());

        // 31 dagen geleden: weg, inclusief de laatste-positie-cache; de check-in houdt afstand + uitkomst maar verliest de coördinaten.
        $p->update(['window_from' => now()->subDays(32), 'window_until' => now()->subDays(31)]);
        $this->artisan('kako:purge-positions', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, KakoAppPosition::count());
        $this->artisan('kako:purge-positions')->assertSuccessful();
        $this->assertSame(0, KakoAppPosition::count());
        $this->assertNull($p->fresh()->last_lat);
        $c = KakoAppCheckin::first();
        $this->assertNull($c->lat);
        $this->assertSame('ok', $c->result);
        $this->assertNotNull($c->distance_m);
    }

    public function test_retention_follows_the_assignment_setting(): void
    {
        $a = $this->assignment(['mode' => 'both', 'appMode' => ['tracking' => ['retentionDays' => 7]]]);
        $p = $this->joined($a);
        $this->openWindow($a);
        $this->consent($a);
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$a->id}/positions", ['samples' => [$this->sample()]])->assertOk();
        $p->update(['window_from' => now()->subDays(10), 'window_until' => now()->subDays(9)]);
        $this->artisan('kako:purge-positions')->assertSuccessful();
        $this->assertSame(0, KakoAppPosition::count());
    }

    public function test_deleting_the_account_removes_positions_but_keeps_the_slot_anonymously(): void
    {
        $p = $this->joined();
        $this->openWindow();
        $this->consent();
        $this->as($this->jansen)->postJson("/api/v1/route-assignments/{$this->a->id}/positions", ['samples' => [$this->sample()]])->assertOk();
        $this->checkin()->assertCreated();

        $this->jansen->delete();
        $this->assertSame(0, KakoAppPosition::count());
        $p = $p->fresh();
        $this->assertNull($p->user_id);
        $this->assertSame('Jansen', $p->display_name);
        $this->assertNull(KakoAppCheckin::first()->user_id);
    }
}
