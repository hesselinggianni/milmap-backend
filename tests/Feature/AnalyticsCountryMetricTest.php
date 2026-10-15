<?php

namespace Tests\Feature;

use App\Http\Controllers\AppEventController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalyticsCountryMetricTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolated tables: no production data and no unrelated migrations.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['app_events', 'site_events'] as $table) {
            Schema::create($table, function (Blueprint $table) {
                $table->id();
                foreach (['type', 'name', 'label', 'platform', 'country', 'visitor_hash', 'event', 'path', 'utm_source', 'utm_medium', 'utm_campaign'] as $column) {
                    $table->string($column)->nullable();
                }
                $table->boolean('is_authed')->nullable();
                $table->dateTime('occurred_at');
            });
        }
        $event = ['type' => 'route', 'name' => '/hub', 'platform' => 'web', 'country' => 'NL', 'visitor_hash' => 'visitor-day-one', 'occurred_at' => '2026-10-09 12:00:00'];
        DB::table('app_events')->insert([
            $event, $event,
            array_replace($event, ['type' => 'action']),
            array_replace($event, ['visitor_hash' => 'visitor-day-two', 'occurred_at' => '2026-10-10 12:00:00']),
            array_replace($event, ['platform' => 'ios', 'country' => 'DE', 'visitor_hash' => 'another-visitor']),
            array_replace($event, ['country' => null, 'visitor_hash' => 'unknown-country']),
            array_replace($event, ['occurred_at' => '2026-10-08 12:00:00']),
        ]);
        DB::table('site_events')->insert([
            ['event' => 'pageview', 'path' => '/', 'country' => 'NL', 'visitor_hash' => 'visitor-day-one', 'occurred_at' => '2026-10-09 12:00:00'],
        ]);
    }

    private function summary(array $params = []): array
    {
        $request = Request::create('/api/v1/admin/analytics', 'GET', array_replace([
            'from' => '2026-10-09', 'to' => '2026-10-10', 'platform' => 'all',
        ], $params));
        return app(AppEventController::class)->adminSummary($request)->getData(true);
    }

    public function test_default_counts_events_and_visitors_deduplicate_within_each_source(): void
    {
        $events = $this->summary();
        $visitors = $this->summary(['country_metric' => 'visitors']);
        $this->assertSame('events', $events['country_metric']);
        $this->assertEquals(['NL' => 5, 'DE' => 1, '??' => 1], array_column($events['by_country'], 'total', 'country'));
        // Repeated actions collapse; daily hashes and website/app each count.
        $this->assertEquals(['NL' => 3, 'DE' => 1, '??' => 1], array_column($visitors['by_country'], 'total', 'country'));
        $this->assertSame($events['totals'], $visitors['totals']);
    }

    public function test_country_visitors_respect_platform_and_exact_date_filters(): void
    {
        $web = $this->summary(['country_metric' => 'visitors', 'platform' => 'web', 'to' => '2026-10-09']);
        $this->assertEquals(['NL' => 1, '??' => 1], array_column($web['by_country'], 'total', 'country'));
        $site = $this->summary(['country_metric' => 'visitors', 'platform' => 'site']);
        $this->assertSame([['country' => 'NL', 'total' => 1]], $site['by_country']);
    }

    public function test_invalid_metric_falls_back_to_events(): void
    {
        $this->assertSame($this->summary()['by_country'], $this->summary(['country_metric' => 'invalid'])['by_country']);
    }

    public function test_country_ranking_uses_the_selected_metric(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('app_events')->insert([
                'type' => 'route', 'name' => '/hub', 'platform' => 'web',
                'country' => 'DE', 'visitor_hash' => 'german-visitor-' . $i,
                'occurred_at' => '2026-10-09 12:00:00',
            ]);
        }
        $this->assertSame('NL', $this->summary()['by_country'][0]['country']);
        $this->assertSame('DE', $this->summary(['country_metric' => 'visitors'])['by_country'][0]['country']);
    }
}
