<?php
namespace Tests\Feature;

use App\Models\{ConnectDevice, Map, User};
use App\Services\{InvitationPush, InvitationService, PushSender};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Mail};
use Illuminate\Support\Str;
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

    private function device(User $u, string $platform = 'android', ?string $token = 'fcm-token-1'): ConnectDevice
    {
        return ConnectDevice::create(['user_id' => $u->id, 'installation_id' => (string) Str::uuid(), 'name' => 'Test', 'platform' => $platform, 'push_token' => $token, 'push_platform' => $token ? $platform : null, 'last_seen_at' => now()]);
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

    public function test_invited_existing_user_gets_a_push_on_every_device_and_tokenless_devices_are_skipped(): void
    {
        $this->fcmConfigured();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);
        $owner = $this->user('Eigenaar'); $guest = $this->user('Gast');
        $this->device($guest, 'android', 'fcm-a'); $this->device($guest, 'android', 'fcm-b'); $this->device($guest, 'android', null);
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Oefenterrein']);

        $invite = app(InvitationService::class)->createInvite($map, $guest->email, 'viewer', $owner)['invitation'];
        $this->app->terminate(); // de push loopt na de respons

        $sent = Http::recorded(fn ($req) => str_contains($req->url(), 'messages:send'))->values();
        $this->assertCount(2, $sent);
        $body = $sent[0][0]->data()['message'];
        $this->assertSame('Nieuwe uitnodiging', $body['notification']['title']);
        $this->assertSame('/invite/' . $invite->token, $body['data']['route']);
        $this->assertSame('invitation', $body['data']['action']);
    }

    public function test_no_push_for_new_users_blocked_users_or_without_config(): void
    {
        $this->fcmConfigured();
        Http::fake();
        $owner = $this->user('Eigenaar'); $blocked = $this->user('Geblokkeerd');
        $this->device($blocked);
        $blocked->forceFill(['blocked_at' => now()])->save();
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'K']);

        app(InvitationService::class)->createInvite($map, 'nieuw-' . Str::random(6) . '@example.test', 'viewer', $owner);
        $invite = app(InvitationService::class)->createInvite($map, $blocked->email, 'viewer', $owner)['invitation'];
        $this->app->terminate();
        Http::assertNothingSent();

        putenv('FCM_SERVICE_ACCOUNT_PATH');
        $ok = $this->user('Ok'); $this->device($ok);
        $this->assertSame(0, PushSender::alertToUser($ok->id, 't', 'b')); // geen config: stil overslaan
    }

    public function test_dead_token_is_cleared_after_fcm_reports_unregistered(): void
    {
        $this->fcmConfigured();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404)]);
        $u = $this->user('Gast'); $d = $this->device($u);

        $this->assertSame(0, PushSender::alertToUser($u->id, 't', 'b'));
        $this->assertNull($d->fresh()->push_token);
    }
}
