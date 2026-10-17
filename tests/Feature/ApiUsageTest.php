<?php
namespace Tests\Feature;

use App\Models\{ApiUsageHourly, User};
use App\Services\ApiUsageMeter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Exceptions, Http, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ApiUsageMeter::discard(); // geen tellers uit een vorige test meenemen
    }

    private function user(bool $admin = false): User
    {
        $user = User::create(['first_name' => 'Test', 'last_name' => 'Gebruiker', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
        $user->forceFill(['is_admin' => $admin])->save();
        return $user;
    }

    private function client(?User $user, string $ip = '127.0.0.1', array $abilities = ['user']): self
    {
        app('auth')->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        if (!$user) return $this->withHeaders(['Authorization' => '']);
        return $this->withToken($user->createToken('test', $abilities, now()->addHour())->plainTextToken);
    }

    private function ingest(array $items)
    {
        return $this->postJson('/api/v1/api-usage', ['items' => $items]);
    }

    private function rows()
    {
        return DB::table('api_usage_hourly')->orderBy('id')->get();
    }

    public function test_guest_and_user_ingest_aggregate_into_one_hourly_row_per_user_and_network(): void
    {
        $user = $this->user();

        // Gast: dubbele (provider, kind) in één batch → één rij.
        $this->client(null)->ingest([
            ['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 5, 'errors' => 1],
            ['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 2, 'errors' => 0],
        ])->assertNoContent();
        $this->client(null)->ingest([['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 3]])->assertNoContent();

        // Ingelogd vanaf hetzelfde netwerk: eigen rij, ook opgeteld.
        $this->client($user)->ingest([['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 4, 'errors' => 2]])->assertNoContent();
        $this->client($user)->ingest([['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 6]])->assertNoContent();

        // Zelfde gebruiker op een ander netwerk: aparte rij.
        $this->client($user, '203.0.113.9')->ingest([['provider' => 'osrm', 'kind' => 'route', 'requests' => 1]])->assertNoContent();

        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $local = ApiUsageMeter::networkHash('127.0.0.1');
        $this->assertSame(16, strlen($local));

        [$guest, $authed, $other] = $rows->all();
        $this->assertSame(['open_meteo', 'forecast', 'client', 0, $local, 10, 1, 0],
            [$guest->provider, $guest->kind, $guest->source, (int) $guest->user_id, $guest->network_hash, (int) $guest->requests, (int) $guest->errors, (int) $guest->cache_hits]);
        $this->assertSame([$user->id, $local, 10, 2], [(int) $authed->user_id, $authed->network_hash, (int) $authed->requests, (int) $authed->errors]);
        $this->assertSame(['osrm', $user->id, ApiUsageMeter::networkHash('203.0.113.9')], [$other->provider, (int) $other->user_id, $other->network_hash]);
        $this->assertNotSame($local, $other->network_hash);
        // Uur-bucket in UTC, nooit een rauw IP in de tabel.
        $this->assertSame(gmdate('Y-m-d H:00:00'), substr((string) $guest->bucket, 0, 19));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($rows));
    }

    public function test_unknown_provider_is_stored_as_other(): void
    {
        $this->client(null)->ingest([
            ['provider' => 'Some_New_Tile_Host', 'kind' => 'tiles', 'requests' => 3],
            ['provider' => 'MAPBOX', 'kind' => 'static_image', 'requests' => 1],
        ])->assertNoContent();
        $this->assertEqualsCanonicalizing(['other', 'mapbox'], $this->rows()->pluck('provider')->all());
    }

    public function test_validation_limits(): void
    {
        $item = ['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 1];
        $this->client(null)->ingest(array_fill(0, 51, $item))->assertStatus(422);
        $this->client(null)->ingest([array_replace($item, ['requests' => 50001])])->assertStatus(422);
        $this->client(null)->ingest([array_replace($item, ['errors' => 50001])])->assertStatus(422);
        $this->client(null)->ingest([array_replace($item, ['kind' => 'Bad-Kind'])])->assertStatus(422);
        $this->client(null)->ingest([array_replace($item, ['kind' => str_repeat('a', 41)])])->assertStatus(422);
        $this->client(null)->ingest([])->assertStatus(422);
        $this->assertCount(0, $this->rows());
        // Precies op de grens mag wel.
        $this->client(null)->ingest(array_fill(0, 50, array_replace($item, ['requests' => 50000])))->assertNoContent();
        $this->assertSame(2500000, (int) $this->rows()->first()->requests);
    }

    public function test_ingest_is_rate_limited_per_user_and_generously_per_ip(): void
    {
        config(['api_usage.ingest_limits' => ['user' => 2, 'ip' => 3]]);
        $item = [['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 1]];
        $user = $this->user();

        for ($i = 0; $i < 2; $i++) $this->client($user)->ingest($item)->assertNoContent();
        $this->client($user)->ingest($item)->assertStatus(429);

        // Gasten op hetzelfde IP hebben een eigen (ruimere) emmer.
        for ($i = 0; $i < 3; $i++) $this->client(null)->ingest($item)->assertNoContent();
        $this->client(null)->ingest($item)->assertStatus(429);
        // Ander netwerk: niet geraakt.
        $this->client(null, '198.51.100.20')->ingest($item)->assertNoContent();

        $this->assertSame(6, (int) DB::table('api_usage_hourly')->sum('requests'));
    }

    public function test_admin_endpoints_require_admin(): void
    {
        $this->client(null)->getJson('/api/v1/admin/api-usage')->assertUnauthorized();
        $this->client($this->user())->getJson('/api/v1/admin/api-usage')->assertForbidden();
        $this->client($this->user())->getJson('/api/v1/admin/users/1/api-usage')->assertForbidden();
        $this->client($this->user(true), '127.0.0.1', ['admin'])->getJson('/api/v1/admin/api-usage')->assertOk();
    }

    public function test_admin_summary_returns_totals_top_users_networks_and_warnings(): void
    {
        $admin  = $this->user(true);
        $pupil  = $this->user();
        $school = ApiUsageMeter::networkHash('203.0.113.50');
        $home   = ApiUsageMeter::networkHash('198.51.100.7');
        $hour   = gmdate('Y-m-d H:00:00');
        $row = fn (array $o) => array_replace(['bucket' => $hour, 'provider' => 'open_meteo', 'kind' => 'forecast', 'source' => 'client',
            'user_id' => 0, 'network_hash' => '', 'requests' => 0, 'errors' => 0, 'cache_hits' => 0], $o);

        ApiUsageMeter::write([
            // School-NAT: 8.500 Open-Meteo-calls in één uur (leerling + gast-iPads).
            $row(['user_id' => $pupil->id, 'network_hash' => $school, 'requests' => 4500, 'errors' => 3]),
            $row(['user_id' => 0, 'network_hash' => $school, 'requests' => 4000]),
            $row(['user_id' => $pupil->id, 'network_hash' => $school, 'provider' => 'mapbox', 'kind' => 'tiles', 'requests' => 900]),
            // Thuis: ruim binnen de limiet.
            $row(['user_id' => $pupil->id, 'network_hash' => $home, 'requests' => 100]),
            // Nominatim via onze proxy: 4.000 waarvan 100 uit cache → 3.900 upstream > 1 req/s gemiddeld.
            $row(['provider' => 'nominatim', 'kind' => 'reverse', 'source' => 'server', 'user_id' => $pupil->id, 'network_hash' => $school, 'requests' => 4000, 'cache_hits' => 100]),
            // Buiten het 7d-venster: telt niet mee.
            $row(['bucket' => gmdate('Y-m-d H:00:00', time() - 20 * 86400), 'user_id' => $pupil->id, 'network_hash' => $school, 'requests' => 99999]),
        ]);

        $json = $this->client($admin, '127.0.0.1', ['admin'])->getJson('/api/v1/admin/api-usage')->assertOk()->json();

        $this->assertSame('7d', $json['range']);
        $this->assertSame('day', $json['granularity']);
        $this->assertCount(7, $json['buckets']);
        $this->assertContains('open_meteo', array_column($json['providers'], 'key'));
        $this->assertSame(10000, collect($json['providers'])->firstWhere('key', 'open_meteo')['limits']['day']);

        $totals = collect($json['totals'])->keyBy('provider');
        $this->assertSame(8600, $totals['open_meteo']['requests']);
        $this->assertSame(8600, $totals['open_meteo']['client_requests']);
        $this->assertSame(3, $totals['open_meteo']['errors']);
        $this->assertSame(['requests' => 4000, 'cache_hits' => 100, 'upstream' => 3900, 'server_requests' => 4000],
            array_intersect_key($totals['nominatim'], array_flip(['requests', 'cache_hits', 'upstream', 'server_requests'])));
        $this->assertSame('open_meteo', $json['totals'][0]['provider']); // gesorteerd op volume

        $this->assertSame(['t' => gmdate('Y-m-d'), 'provider' => 'open_meteo', 'requests' => 8600],
            collect($json['series'])->firstWhere('provider', 'open_meteo'));

        $top = $json['top_users'][0];
        $this->assertSame($pupil->id, $top['user_id']);
        $this->assertSame('Test Gebruiker', $top['name']);
        $this->assertSame(4500 + 900 + 100 + 4000, $top['requests']);
        $this->assertSame(['open_meteo' => 4600, 'nominatim' => 4000, 'mapbox' => 900], $top['by_provider']);
        $this->assertSame(2, $top['networks']);
        $guest = collect($json['top_users'])->firstWhere('user_id', 0);
        $this->assertTrue($guest['is_guest']);
        $this->assertSame(4000, $guest['requests']);

        $net = $json['top_networks'][0];
        $this->assertSame($school, $net['network_hash']);
        $this->assertSame(1, $net['users']);
        $this->assertSame(4000, $net['guest_requests']);
        $this->assertSame(8500, $net['max_day_open_meteo']);
        $this->assertSame(8500, $net['max_hour_open_meteo']);
        $this->assertSame(['open_meteo' => 8500, 'nominatim' => 4000, 'mapbox' => 900], $net['by_provider']);
        $this->assertSame(100, collect($json['top_networks'])->firstWhere('network_hash', $home)['max_day_open_meteo']);

        $warnings = collect($json['warnings']);
        $om = $warnings->where('provider', 'open_meteo')->where('network_hash', $school);
        $this->assertEquals(['day' => 'warning', 'hour' => 'critical'], $om->pluck('level', 'period')->sortKeys()->all());
        $this->assertSame(8500, $om->firstWhere('period', 'day')['value']);
        $this->assertStringContainsString('8.500', $om->firstWhere('period', 'day')['message']);
        $this->assertNull($warnings->where('provider', 'open_meteo')->firstWhere('network_hash', $home));
        $nom = $warnings->firstWhere('provider', 'nominatim');
        $this->assertSame(['hour', 3900, 3600, 'critical', 'server'], [$nom['period'], $nom['value'], $nom['limit'], $nom['level'], $nom['source']]);
        $this->assertArrayNotHasKey('network_hash', $nom);
        $this->assertSame('critical', $json['warnings'][0]['level']);

        // Filters: provider + netwerk + gebruiker, en 24h-venster per uur.
        $filtered = $this->getJson('/api/v1/admin/api-usage?range=24h&provider=open_meteo&network=' . $home)->assertOk()->json();
        $this->assertSame('hour', $filtered['granularity']);
        $this->assertCount(24, $filtered['buckets']);
        $this->assertSame([['provider' => 'open_meteo', 'requests' => 100]], array_map(fn ($t) => ['provider' => $t['provider'], 'requests' => $t['requests']], $filtered['totals']));
        $this->assertSame(str_replace(' ', 'T', substr($hour, 0, 13)) . ':00:00Z', $filtered['series'][0]['t']);
        $this->assertSame([], $filtered['warnings']);
        $guestOnly = $this->getJson('/api/v1/admin/api-usage?user_id=0')->assertOk()->json();
        $this->assertSame(4000, $guestOnly['totals'][0]['requests']);
        $this->getJson('/api/v1/admin/api-usage?range=1y')->assertStatus(422);
        $this->getJson('/api/v1/admin/api-usage?network=not-a-hash')->assertStatus(422);

        // Per-gebruiker endpoint.
        // 30d: nu telt ook de rij van 20 dagen geleden mee.
        $user = $this->getJson("/api/v1/admin/users/{$pupil->id}/api-usage?range=30d")->assertOk()->json();
        $this->assertSame($pupil->id, $user['user']['user_id']);
        $this->assertSame(9500 + 99999, array_sum(array_column($user['totals'], 'requests')));
        $this->assertCount(30, $user['buckets']);
        $this->assertSame(2, count($user['networks']));
        $this->assertSame(['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 4600 + 99999, 'errors' => 3, 'cache_hits' => 0],
            collect($user['by_kind'])->firstWhere('provider', 'open_meteo'));
    }

    public function test_server_meter_counts_upstream_calls_cache_hits_and_errors_via_geo_proxy(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response(['address' => ['city' => 'Oirschot', 'country_code' => 'nl']]),
            'overpass-api.de/*'             => Http::response('kapot', 504),
        ]);
        $user = $this->user();

        $this->client($user, '203.0.113.50')->getJson('/api/v1/geo/reverse?lat=51.5&lon=5.3')->assertOk()->assertJsonPath('city', 'Oirschot');
        $this->client($user, '203.0.113.50')->getJson('/api/v1/geo/reverse?lat=51.5&lon=5.3')->assertOk(); // uit cache
        $this->client(null, '203.0.113.50')->getJson('/api/v1/geo/reverse?lat=51.5&lon=5.3')->assertOk();  // gast, cache
        $this->client(null)->postJson('/api/v1/geo/overpass', ['query' => '[out:json];node(1);out;'])->assertOk();
        Http::assertSentCount(2);

        $rows = $this->rows()->keyBy(fn ($r) => $r->provider . ':' . $r->user_id);
        $this->assertSame(['reverse', 'server', ApiUsageMeter::networkHash('203.0.113.50'), 2, 1, 0],
            [$rows["nominatim:{$user->id}"]->kind, $rows["nominatim:{$user->id}"]->source, $rows["nominatim:{$user->id}"]->network_hash,
             (int) $rows["nominatim:{$user->id}"]->requests, (int) $rows["nominatim:{$user->id}"]->cache_hits, (int) $rows["nominatim:{$user->id}"]->errors]);
        $this->assertSame([1, 1], [(int) $rows['nominatim:0']->requests, (int) $rows['nominatim:0']->cache_hits]);
        $this->assertSame([1, 1, 0], [(int) $rows['overpass:0']->requests, (int) $rows['overpass:0']->errors, (int) $rows['overpass:0']->cache_hits]);
    }

    public function test_meter_never_throws_even_when_table_is_missing(): void
    {
        Exceptions::fake();
        Schema::drop('api_usage_hourly');

        ApiUsageMeter::record('mapbox', 'tiles');
        ApiUsageMeter::record('', '!!!', ['requests' => -5, 'errors' => 'x']);
        ApiUsageMeter::flush();
        $res = ApiUsageMeter::measure('osrm', 'route', fn () => 'resultaat');
        ApiUsageMeter::flush();
        $this->assertSame('resultaat', $res);
        Exceptions::assertReported(QueryException::class);

        // De exception van de call zelf komt ongewijzigd terug bij de aanroeper.
        try {
            ApiUsageMeter::measure('osrm', 'route', fn () => throw new \RuntimeException('upstream stuk'));
            $this->fail('exception verwacht');
        } catch (\RuntimeException $e) {
            $this->assertSame('upstream stuk', $e->getMessage());
        }
        ApiUsageMeter::flush();

        // Ingest geeft een 5xx (client voegt terug en probeert later) i.p.v. een crash.
        $this->client(null)->ingest([['provider' => 'open_meteo', 'kind' => 'forecast', 'requests' => 1]])->assertStatus(503);
    }

    public function test_meter_buffers_within_a_request_and_normalises_input(): void
    {
        ApiUsageMeter::record('mapbox', 'tiles', ['user_id' => 5, 'network_hash' => 'abcdef0123456789']);
        ApiUsageMeter::record('mapbox', 'tiles', ['user_id' => 5, 'network_hash' => 'abcdef0123456789', 'cache_hits' => 1]);
        ApiUsageMeter::record('NotAProvider', 'Bad Kind!', ['user_id' => 5, 'network_hash' => '']);
        $this->assertCount(0, $this->rows()); // pas bij terminate/flush
        ApiUsageMeter::flush();

        $rows = $this->rows()->keyBy('provider');
        $this->assertSame([2, 1, 'server'], [(int) $rows['mapbox']->requests, (int) $rows['mapbox']->cache_hits, $rows['mapbox']->source]);
        $this->assertSame('other', $rows['other']->kind);
    }

    public function test_prune_removes_rows_older_than_retention(): void
    {
        $row = ['provider' => 'open_meteo', 'kind' => 'forecast', 'source' => 'client', 'user_id' => 0, 'network_hash' => '', 'requests' => 1, 'errors' => 0, 'cache_hits' => 0];
        ApiUsageMeter::write([
            ['bucket' => gmdate('Y-m-d H:00:00', time() - 200 * 86400)] + $row,
            ['bucket' => gmdate('Y-m-d H:00:00', time() - 10 * 86400)] + $row,
        ]);
        $this->artisan('model:prune', ['--model' => [ApiUsageHourly::class]])->assertSuccessful();
        $this->assertSame(1, ApiUsageHourly::count());
    }
}
