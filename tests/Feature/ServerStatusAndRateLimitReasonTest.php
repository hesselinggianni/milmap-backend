<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServerStatusAndRateLimitReasonTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir().'/milmap-status-'.Str::random(8);
        mkdir($this->backupDir);
        // Geen echte netwerk- of systemctl-aanroepen in de test.
        config(['server_status.domains' => [], 'server_status.services' => [], 'server_status.backup_path' => $this->backupDir, 'server_status.backup_keep' => 2]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir.'/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->backupDir);
        parent::tearDown();
    }

    private function user(bool $admin = false): User
    {
        $user = User::create(['first_name' => 'Test', 'last_name' => 'Account', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
        $user->forceFill(['is_admin' => $admin])->save();
        return $user;
    }

    private function login(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('test', ['admin'], now()->addHour())->plainTextToken);
    }

    public function test_server_status_requires_admin(): void
    {
        $this->getJson('/api/v1/admin/server-status')->assertUnauthorized();
        $this->login($this->user());
        $this->getJson('/api/v1/admin/server-status')->assertForbidden();
    }

    public function test_server_status_reports_sections_and_backup_findings(): void
    {
        // 3 frontend-back-ups (bewaarbeleid is 2), een lege database-dump en een echte.
        foreach (['frontend_20261001_100000.tar.gz', 'frontend_20261002_100000.tar.gz', 'frontend_20261003_100000.tar.gz'] as $f) {
            file_put_contents($this->backupDir.'/'.$f, str_repeat('x', 2048));
        }
        file_put_contents($this->backupDir.'/db_20261003_100000.sql.gz', 'x');
        file_put_contents($this->backupDir.'/db_20260901_100000.sql.gz', str_repeat('x', 4096));
        touch($this->backupDir.'/db_20260901_100000.sql.gz', time() - 20 * 86400);
        touch($this->backupDir.'/db_20261003_100000.sql.gz', time() - 20 * 86400);

        $this->login($this->user(true));
        $res = $this->getJson('/api/v1/admin/server-status?fresh=1')->assertOk();

        $res->assertJsonStructure(['status', 'generated_at', 'system', 'services', 'domains', 'backups' => ['types', 'total_bytes', 'count', 'recent'], 'database' => ['ms'], 'queue', 'app' => ['laravel', 'php'], 'rate_limits', 'checks']);
        $this->assertSame(5, $res->json('backups.count'));
        $frontend = collect($res->json('backups.types'))->firstWhere('type', 'frontend');
        $this->assertSame(3, $frontend['count']);
        $this->assertTrue($frontend['over_keep']);
        $this->assertSame(1, $res->json('backups.empty_dumps'));

        // Oude database-back-up en lege dump moeten als aandachtspunt/fout terugkomen.
        $keys = collect($res->json('checks'))->keyBy('key');
        $this->assertSame('fail', $keys['backup.db']['status']);
        $this->assertSame('warn', $keys['backup.empty']['status']);
        $this->assertSame('fail', $res->json('status'));
    }

    public function test_unreadable_services_are_not_reported_as_an_outage(): void
    {
        config(['server_status.services' => ['milmap-service-die-niet-bestaat']]);

        $this->login($this->user(true));
        $res = $this->getJson('/api/v1/admin/server-status?fresh=1')->assertOk();

        // Zonder systemd (of een onbekende unit) hoort de pagina "niet te lezen" te tonen, geen rode storing.
        $failing = collect($res->json('checks'))->where('status', 'fail')->pluck('key')->all();
        $this->assertNotContains('service.milmap-service-die-niet-bestaat', $failing);
    }

    public function test_throttle_429_is_logged_with_a_readable_reason(): void
    {
        Route::middleware(['api', 'throttle:2,1'])->get('/api/v1/_rl-test', fn () => 'ok');

        $this->getJson('/api/v1/_rl-test')->assertOk();
        $this->getJson('/api/v1/_rl-test')->assertOk();
        $this->getJson('/api/v1/_rl-test')->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        $row = DB::table('rate_limit_events')->first();
        $this->assertSame('internal', $row->source);
        $this->assertSame('throttle', $row->limiter);
        $this->assertSame('Routelimiet: max 2 per minuut (per IP)', $row->reason);
        $this->assertSame(1, (int) $row->hits);
    }

    public function test_named_limiter_reason_mentions_the_limiter(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('api', fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute(1)->by('test'));
        Route::middleware(['api'])->get('/api/v1/_rl-named', fn () => 'ok');

        $this->getJson('/api/v1/_rl-named')->assertOk();
        $this->getJson('/api/v1/_rl-named')->assertStatus(429);

        $row = DB::table('rate_limit_events')->first();
        $this->assertSame('api', $row->limiter);
        $this->assertSame('Algemene API-limiet: max 1 per minuut', $row->reason);
    }

    public function test_manual_controller_429_is_caught_with_reason_and_not_double_counted(): void
    {
        // Admin-inlogcode: de controller telt zelf en geeft na 5 aanvragen per e-mailadres een 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/request-code', ['email' => 'nobody@example.test'])->assertOk();
        }
        $this->postJson('/api/v1/admin/request-code', ['email' => 'nobody@example.test'])->assertStatus(429);

        $row = DB::table('rate_limit_events')->first();
        $this->assertSame(1, DB::table('rate_limit_events')->count());
        $this->assertSame('controller', $row->limiter);
        $this->assertSame('Admin-inlogcode aanvragen: max 5 per e-mailadres per uur', $row->reason);
        $this->assertSame('api/v1/admin/request-code', $row->route);
        $this->assertSame(1, (int) $row->hits);
    }

    public function test_unknown_manual_429_falls_back_to_the_response_message(): void
    {
        Route::middleware(['api'])->get('/api/v1/_rl-manual', fn () => response()->json(['message' => 'Even geduld'], 429));

        $this->getJson('/api/v1/_rl-manual')->assertStatus(429);

        $this->assertSame('Eigen limiet: Even geduld', DB::table('rate_limit_events')->value('reason'));
    }

    public function test_admin_index_summarises_by_route_user_and_ip(): void
    {
        $victim = $this->user();
        $now = now();
        $row = fn (array $o) => array_merge(['bucket' => Str::random(40), 'source' => 'internal', 'method' => 'POST', 'limiter' => null, 'reason' => null, 'user_id' => null, 'ip' => null, 'hits' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now], $o);
        DB::table('rate_limit_events')->insert([
            $row(['route' => 'api/v1/login', 'reason' => 'Routelimiet: max 10 per minuut (per IP)', 'limiter' => 'throttle', 'hits' => 7, 'ip' => '10.0.0.1']),
            $row(['route' => 'api/v1/login', 'reason' => 'Routelimiet: max 10 per minuut (per IP)', 'limiter' => 'throttle', 'hits' => 3, 'ip' => '10.0.0.2']),
            $row(['route' => 'api/v1/tiles', 'method' => 'GET', 'reason' => 'Algemene API-limiet: max 600 per minuut', 'limiter' => 'api', 'hits' => 5, 'user_id' => $victim->id, 'ip' => '10.0.0.3']),
            $row(['route' => 'api/v1/old', 'hits' => 99, 'last_seen_at' => $now->copy()->subDays(40), 'first_seen_at' => $now->copy()->subDays(40)]),
        ]);

        $this->login($this->user(true));
        $res = $this->getJson('/api/v1/admin/rate-limit-events?days=7')->assertOk();

        $this->assertSame(15, $res->json('totals.hits'));
        $this->assertSame('api/v1/login', $res->json('by_route.0.route'));
        $this->assertSame(10, $res->json('by_route.0.hits'));
        $this->assertSame(2, $res->json('by_route.0.ips'));
        $this->assertSame('Routelimiet: max 10 per minuut (per IP)', $res->json('by_route.0.reason'));
        $this->assertSame($victim->id, $res->json('by_user.0.user_id'));
        $this->assertSame('Test Account', $res->json('by_user.0.user_name'));
        $this->assertSame('Test Account', collect($res->json('events'))->firstWhere('user_id', $victim->id)['user_name']);
        $this->assertSame(5, $res->json('by_user.0.hits'));
        $this->assertSame('GET api/v1/tiles', $res->json('by_user.0.top_route'));
        $this->assertSame('10.0.0.1', $res->json('by_ip.0.ip'));
        $this->assertCount(3, $res->json('events'));
    }
}
