<?php
namespace Tests\Feature;

use App\Models\{ConnectDevice, Map, User};
use App\Services\{InvitationPush, InvitationService, PushSender};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Mail};
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Pushmelding bij een uitnodiging voor een bestaand account (FCM; APNs vraagt echte sleutels). */
class InvitationPushTest extends TestCase
{
    use RefreshDatabase;

    private ?string $saPath = null;

    protected function setUp(): void { parent::setUp(); Mail::fake(); Cache::flush(); }
    protected function tearDown(): void { putenv('FCM_SERVICE_ACCOUNT_PATH'); if ($this->saPath) @unlink($this->saPath); parent::tearDown(); }

    private function user(string $tag, string $language = 'nl'): User
    {
        $u = User::create(['first_name' => $tag, 'last_name' => 'Test', 'email' => Str::uuid().'-'.strtolower($tag).'@example.test', 'password' => bcrypt('x-password-1'), 'email_verified_at' => now()]);
        $u->forceFill(['language' => $language])->save();
        return $u;
    }

    /** Een ingelogd toestel: een Sanctum-sessie, met of zonder gemeld push-token. */
    private function loggedIn(User $u, ?string $token = 'fcm-token-1', string $platform = 'android'): PersonalAccessToken
    {
        $t = $u->createToken('test')->accessToken;
        if ($token) {
            $t->forceFill(['push_token' => $token, 'push_platform' => $platform])->save();
        }
        return $t;
    }

    private function device(User $u, string $platform = 'android', ?string $token = 'fcm-token-1'): ConnectDevice
    {
        return ConnectDevice::create(['user_id' => $u->id, 'installation_id' => (string) Str::uuid(), 'name' => 'Test', 'platform' => $platform, 'push_token' => $token, 'push_platform' => $token ? $platform : null, 'last_seen_at' => now()]);
    }

    private function fakeFcm(): void
    {
        $this->fcmConfigured();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);
    }

    private function sentTokens(): array
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), 'messages:send'))
            ->map(fn ($pair) => $pair[0]->data()['message']['token'])->sort()->values()->all();
    }

    private function fcmConfigured(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $this->saPath = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($this->saPath, json_encode(['project_id' => 'milmap-test', 'client_email' => 'x@milmap-test.iam.gserviceaccount.com', 'private_key' => $pem]));
        putenv('FCM_SERVICE_ACCOUNT_PATH=' . $this->saPath);
    }

    public function test_message_is_localised_and_never_contains_an_email_address(): void
    {
        $owner = $this->user('Eigenaar'); $nl = $this->user('Gast', 'nl'); $en = $this->user('Guest', 'en');
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Oefenterrein']);
        $invite = app(InvitationService::class)->createInvite($map, $nl->email, 'viewer', $owner)['invitation'];

        $m = InvitationPush::message($invite, $nl);
        $this->assertSame('Nieuwe uitnodiging', $m['title']);
        $this->assertStringContainsString('kaart “Oefenterrein”', $m['body']);
        $this->assertSame(['action' => 'invitation', 'route' => '/invite/' . $invite->token], $m['data']);
        $this->assertStringNotContainsString('@', $m['body']);

        $this->assertSame('New invitation', InvitationPush::message($invite, $en)['title']);
    }

    public function test_invited_existing_user_gets_a_push_on_every_logged_in_device_only(): void
    {
        $this->fakeFcm();
        $owner = $this->user('Eigenaar'); $guest = $this->user('Gast');
        $this->loggedIn($guest, 'fcm-a'); $this->loggedIn($guest, 'fcm-b'); $this->loggedIn($guest, null);
        $this->loggedIn($guest, 'fcm-expired')->forceFill(['expires_at' => now()->subDay()])->save();
        $this->device($guest, 'android', 'fcm-logged-out'); // Connect-apparaat zonder actieve sessie
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Oefenterrein']);

        $invite = app(InvitationService::class)->createInvite($map, $guest->email, 'viewer', $owner)['invitation'];
        $this->app->terminate(); // de push loopt na de respons

        $this->assertSame(['fcm-a', 'fcm-b'], $this->sentTokens());
        $sent = Http::recorded(fn ($req) => str_contains($req->url(), 'messages:send'))->values();
        $body = $sent[0][0]->data()['message'];
        $this->assertSame('Nieuwe uitnodiging', $body['notification']['title']);
        $this->assertSame('/invite/' . $invite->token, $body['data']['route']);
        $this->assertSame('invitation', $body['data']['action']);
    }

    public function test_connect_device_with_a_session_is_pushed_once(): void
    {
        $this->fakeFcm();
        $owner = $this->user('Eigenaar'); $guest = $this->user('Gast');
        $device = $this->device($guest, 'android', 'fcm-shared');
        $this->loggedIn($guest, 'fcm-shared')->forceFill(['connect_device_id' => $device->id])->save();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'K']);

        app(InvitationService::class)->createInvite($map, $guest->email, 'viewer', $owner);
        $this->app->terminate();

        $this->assertSame(['fcm-shared'], $this->sentTokens());
    }

    public function test_app_registers_its_push_token_on_the_current_session_and_a_new_login_takes_it_over(): void
    {
        $a = $this->user('Anna'); $b = $this->user('Bert');
        $plainA = $a->createToken('app')->plainTextToken;
        $plainB = $b->createToken('app')->plainTextToken;

        $this->withToken($plainA)->postJson('/api/v1/push-token', ['token' => 'apns-1', 'platform' => 'ios'])->assertNoContent();
        $this->assertSame('apns-1', $a->tokens()->first()->push_token);
        $this->assertSame('ios', $a->tokens()->first()->push_platform);

        // Zelfde toestel, ander account: het token verhuist, Anna krijgt hier niets meer.
        $this->app['auth']->forgetGuards();
        $this->withToken($plainB)->postJson('/api/v1/push-token', ['token' => 'apns-1', 'platform' => 'ios'])->assertNoContent();
        $this->assertNull($a->tokens()->first()->push_token);
        $this->assertSame('apns-1', $b->tokens()->first()->push_token);
    }

    public function test_logging_out_stops_the_pushes_to_that_device(): void
    {
        $this->fakeFcm();
        $owner = $this->user('Eigenaar'); $guest = $this->user('Gast');
        $session = $this->loggedIn($guest, 'fcm-a');
        $session->delete(); // uitloggen verwijdert de sessie, en daarmee het token
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'K']);

        app(InvitationService::class)->createInvite($map, $guest->email, 'viewer', $owner);
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_no_push_for_new_users_blocked_users_or_without_config(): void
    {
        $this->fcmConfigured();
        Http::fake();
        $owner = $this->user('Eigenaar'); $blocked = $this->user('Geblokkeerd');
        $this->loggedIn($blocked);
        $blocked->forceFill(['blocked_at' => now()])->save();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'K']);

        app(InvitationService::class)->createInvite($map, 'nieuw-' . Str::random(6) . '@example.test', 'viewer', $owner);
        $invite = app(InvitationService::class)->createInvite($map, $blocked->email, 'viewer', $owner)['invitation'];
        $this->app->terminate();
        Http::assertNothingSent();

        putenv('FCM_SERVICE_ACCOUNT_PATH');
        $ok = $this->user('Ok'); $this->loggedIn($ok);
        $this->assertSame(0, PushSender::alertToUser($ok->id, 't', 'b')); // geen config: stil overslaan
    }

    public function test_dead_token_is_cleared_after_fcm_reports_unregistered(): void
    {
        $this->fcmConfigured();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404)]);
        $u = $this->user('Gast'); $s = $this->loggedIn($u);

        $this->assertSame(0, PushSender::alertToUser($u->id, 't', 'b'));
        $this->assertNull($s->fresh()->push_token);
    }
}
