<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoOverpassProxyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://overpass*';

    public function test_successful_answers_are_cached_so_the_public_instance_is_hit_once(): void
    {
        Http::fake([self::URL => Http::response(['elements' => [['type' => 'way', 'id' => 1]]])]);

        $first = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(1);out;'])->assertOk();
        $second = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(1);out;'])->assertOk();

        $this->assertSame([['type' => 'way', 'id' => 1]], $first->json('elements'));
        $this->assertSame($first->json(), $second->json());
        Http::assertSentCount(1);
    }

    public function test_a_failed_upstream_is_reported_and_never_cached(): void
    {
        Http::fake([self::URL => Http::sequence()->push('Gateway Timeout', 504)->push('Gateway Timeout', 504)->push(['elements' => [['type' => 'way', 'id' => 2]]])]);

        $failed = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(2);out;'])->assertOk();
        $this->assertSame([], $failed->json('elements'));
        $this->assertSame('upstream', $failed->json('error'));

        // De volgende vraag gaat opnieuw naar Overpass in plaats van 12 uur "leeg" terug te krijgen.
        $retry = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(2);out;'])->assertOk();
        $this->assertNull($retry->json('error'));
        $this->assertCount(1, $retry->json('elements'));
        Http::assertSentCount(3); // eerste vraag: beide instanties mislukt; tweede vraag: opnieuw geprobeerd
    }

    public function test_a_query_that_overpass_aborted_counts_as_failed(): void
    {
        Http::fake([self::URL => Http::response(['elements' => [], 'remark' => 'runtime error: Query timed out in "query" at line 1 after 26 seconds.'])]);

        $res = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(3);out;'])->assertOk();

        $this->assertSame('upstream', $res->json('error'));
        $this->assertFalse(Cache::has('geo:overpass:' . sha1('[out:json];way(3);out;')));
    }

    public function test_a_busy_first_instance_falls_back_to_the_second(): void
    {
        Http::fake([
            'https://overpass-api.de/*' => Http::response('Too many requests', 429),
            'https://overpass.kumi.systems/*' => Http::response(['elements' => [['type' => 'way', 'id' => 7]]]),
        ]);

        $res = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(7);out;'])->assertOk();

        $this->assertNull($res->json('error'));
        $this->assertSame(7, $res->json('elements.0.id'));
        Http::assertSentCount(2);
    }

    public function test_all_instances_failing_is_reported_once_and_not_cached(): void
    {
        Http::fake(['https://overpass*' => Http::response('Gateway Timeout', 504)]);

        $res = $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(8);out;'])->assertOk();

        $this->assertSame('upstream', $res->json('error'));
        $this->assertFalse(Cache::has('geo:overpass:' . sha1('[out:json];way(8);out;')));
    }

    public function test_an_empty_but_successful_area_is_cached(): void
    {
        Http::fake([self::URL => Http::response(['elements' => []])]);

        $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(4);out;'])->assertOk();
        $this->postJson('/api/v1/geo/overpass', ['query' => '[out:json];way(4);out;'])->assertOk();

        Http::assertSentCount(1);
    }
}
