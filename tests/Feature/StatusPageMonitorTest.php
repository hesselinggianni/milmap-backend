<?php
namespace Tests\Feature;

use App\Models\Setting;
use App\Services\StatusPageMonitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * milmap.nl/status: de monitor bewaart per domein dagtellers i.p.v. elke losse check
 * (de oude rij `status_page_checks` was ~36 MB en werd elke minuut herschreven).
 * Dekt tellen, bewaartermijn, omzetting van de oude opslag, incidenten en de publieke routes.
 */
class StatusPageMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        Setting::put('status_page_domains', [
            ['id' => 'web', 'name' => 'Webapp', 'url' => 'https://app.example.test', 'description' => null, 'enabled' => true, 'createdAt' => '2026-06-13T09:17:11+00:00'],
            ['id' => 'api', 'name' => 'API', 'url' => 'https://api.example.test', 'description' => null, 'enabled' => true, 'createdAt' => '2026-06-13T09:17:29+00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function monitor(): StatusPageMonitor
    {
        return app(StatusPageMonitor::class);
    }

    /** Http::fake() telt stubs op (de eerste wint), dus eerst een schone client. */
    private function respondWith(int $status): void
    {
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response($status < 400 ? 'ok' : 'boom', $status)]);
    }

    private function stats(): array
    {
        return Setting::get('status_page_stats', []);
    }

    public function test_checks_are_tallied_per_day_and_the_latest_is_kept(): void
    {
        $this->respondWith(200);
        $this->monitor()->monitorAll();
        $this->respondWith(500);
        $this->monitor()->monitorAll();

        $stats = $this->stats();
        $this->assertSame(['up' => 1, 'degraded' => 0, 'down' => 1], $stats['web']['days']['2026-11-01']);
        $this->assertSame('down', $stats['web']['latest']['status']);
        $this->assertSame(500, $stats['web']['latest']['statusCode']);

        $report = collect($this->monitor()->uptimeReport())->keyBy(fn ($r) => $r['domain']['id']);
        $this->assertSame('down', $report['web']['currentStatus']);
        $this->assertSame(50, $report['web']['uptimePct']);
        $this->assertCount(90, $report['web']['dailyBars']);
        $today = $report['web']['dailyBars'][89];
        $this->assertSame(['date' => '2026-11-01', 'uptime' => 50, 'status' => 'down'], $today);
        $this->assertSame('nodata', $report['web']['dailyBars'][88]['status']);
    }

    public function test_calc_uptime_thresholds_and_degraded_counts_half(): void
    {
        $bars = $this->monitor()->calcUptime([
            '2026-11-01' => ['up' => 99, 'degraded' => 0, 'down' => 1],  // 99 % → up
            '2026-10-31' => ['up' => 9, 'degraded' => 2, 'down' => 0],   // (9+1)/11 = 91 % → degraded
            '2026-10-30' => ['up' => 0, 'degraded' => 0, 'down' => 5],   // 0 % → down
        ], 4);

        $this->assertSame([
            ['date' => '2026-10-29', 'uptime' => 100, 'status' => 'nodata'],
            ['date' => '2026-10-30', 'uptime' => 0, 'status' => 'down'],
            ['date' => '2026-10-31', 'uptime' => 91, 'status' => 'degraded'],
            ['date' => '2026-11-01', 'uptime' => 99, 'status' => 'up'],
        ], $bars);
    }

    public function test_the_stored_stats_stay_tiny_after_ninety_days_of_checks(): void
    {
        $days = [];
        for ($i = 0; $i < 90; $i++) {
            $days[Carbon::now('UTC')->subDays($i)->format('Y-m-d')] = ['up' => 1438, 'degraded' => 1, 'down' => 1];
        }
        Setting::put('status_page_stats', ['web' => ['days' => $days, 'latest' => null], 'api' => ['days' => $days, 'latest' => null]]);

        $this->respondWith(200);
        $this->monitor()->monitorAll();

        $this->assertLessThan(20_000, strlen((string) Setting::find('status_page_stats')->value));
    }

    public function test_days_outside_the_retention_window_are_pruned(): void
    {
        Setting::put('status_page_stats', ['web' => ['days' => [
            '2026-07-01' => ['up' => 5, 'degraded' => 0, 'down' => 0],   // 123 dagen geleden
            '2026-08-04' => ['up' => 7, 'degraded' => 0, 'down' => 0],   // 89 dagen geleden: net binnen de 90 balken
            '2026-08-03' => ['up' => 9, 'degraded' => 0, 'down' => 0],   // 90 dagen geleden: net eruit
        ], 'latest' => null]]);

        $this->respondWith(200);
        $this->monitor()->monitorAll();

        $days = $this->stats()['web']['days'];
        $this->assertArrayHasKey('2026-08-04', $days);
        $this->assertArrayNotHasKey('2026-08-03', $days);
        $this->assertArrayNotHasKey('2026-07-01', $days);
    }

    public function test_legacy_checks_are_converted_once_and_the_old_row_is_deleted(): void
    {
        $check = fn (string $domain, string $ts, string $status, ?int $ms = 80) => ['domainId' => $domain, 'timestamp' => $ts, 'status' => $status, 'responseTime' => $ms, 'statusCode' => 200];
        Setting::put('status_page_checks', [
            'web' => [
                $check('web', '2026-10-30T10:00:02+00:00', 'up'),
                $check('web', '2026-10-30T10:01:02+00:00', 'up'),
                $check('web', '2026-10-30T10:02:02+00:00', 'degraded', 2500),
                $check('web', '2026-10-31T09:00:02+00:00', 'down', null),
            ],
            'api' => [$check('api', '2026-10-31T09:00:02+00:00', 'up')],
        ]);

        $this->respondWith(200);
        $this->monitor()->monitorAll();

        $this->assertNull(Setting::find('status_page_checks'), 'oude rij moet weg');
        $web = $this->stats()['web'];
        $this->assertSame(['up' => 2, 'degraded' => 1, 'down' => 0], $web['days']['2026-10-30']);
        $this->assertSame(['up' => 0, 'degraded' => 0, 'down' => 1], $web['days']['2026-10-31']);
        $this->assertSame(['up' => 1, 'degraded' => 0, 'down' => 0], $web['days']['2026-11-01']);
        $this->assertSame('up', $web['latest']['status']);

        // 3 van de 5 checks waren 'up' → 60 % (degraded telt voor het totaalpercentage niet mee, zoals voorheen).
        $report = collect($this->monitor()->uptimeReport())->keyBy(fn ($r) => $r['domain']['id']);
        $this->assertSame(60, $report['web']['uptimePct']);
    }

    public function test_the_legacy_row_survives_a_web_request_and_nothing_is_converted_there(): void
    {
        Setting::put('status_page_checks', ['web' => [['domainId' => 'web', 'timestamp' => '2026-10-31T09:00:02+00:00', 'status' => 'up', 'responseTime' => 80]]]);

        $res = $this->getJson('/api/v1/status/uptime')->assertOk();

        $this->assertSame('unknown', $res->json('0.currentStatus'));
        $this->assertNotNull(Setting::find('status_page_checks'));
        $this->assertNull(Setting::find('status_page_stats'));
    }

    public function test_history_of_deleted_domains_is_dropped_but_disabled_domains_keep_theirs(): void
    {
        Setting::put('status_page_domains', [
            ['id' => 'web', 'name' => 'Webapp', 'url' => 'https://app.example.test', 'enabled' => false],
            ['id' => 'api', 'name' => 'API', 'url' => 'https://api.example.test', 'enabled' => true],
        ]);
        $day = ['2026-11-01' => ['up' => 3, 'degraded' => 0, 'down' => 0]];
        Setting::put('status_page_stats', [
            'web' => ['days' => $day, 'latest' => null],
            'gone' => ['days' => $day, 'latest' => null],
        ]);

        $this->respondWith(200);
        $this->monitor()->monitorAll();

        $stats = $this->stats();
        $this->assertArrayHasKey('web', $stats);
        $this->assertArrayHasKey('api', $stats);
        $this->assertArrayNotHasKey('gone', $stats);
    }

    public function test_an_incident_opens_when_a_domain_goes_down_and_resolves_on_recovery(): void
    {
        $this->respondWith(200);
        $this->monitor()->monitorAll();
        $this->assertSame([], $this->monitor()->incidents());

        $this->respondWith(503);
        $this->monitor()->monitorAll();
        $this->monitor()->monitorAll(); // blijft down: geen tweede incident per domein

        $incidents = $this->monitor()->incidents();
        $this->assertCount(2, $incidents); // web + api
        $this->assertSame(['investigating', 'investigating'], array_column($incidents, 'status'));

        $this->respondWith(200);
        $this->monitor()->monitorAll();

        $this->assertSame(['resolved', 'resolved'], array_column($this->monitor()->incidents(), 'status'));
    }

    public function test_public_endpoints_return_the_report_with_a_short_cache_header(): void
    {
        $this->respondWith(200);
        $this->monitor()->monitorAll();

        $uptime = $this->getJson('/api/v1/status/uptime')->assertOk();
        $uptime->assertJsonCount(2);
        $uptime->assertJsonPath('0.domain.id', 'web');
        $uptime->assertJsonPath('0.currentStatus', 'up');
        $uptime->assertJsonCount(90, '0.dailyBars');
        $this->assertStringContainsString('max-age=30', (string) $uptime->headers->get('Cache-Control'));

        $this->getJson('/api/v1/status/incidents')->assertOk()->assertExactJson([]);
    }
}
