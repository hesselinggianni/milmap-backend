<?php

namespace Tests\Unit;

use App\Support\RouteGeometry;
use PHPUnit\Framework\TestCase;

class RouteGeometryTest extends TestCase
{
    public function test_encode_matches_the_published_polyline_example(): void
    {
        $points = [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]];

        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', RouteGeometry::encode($points));
    }

    public function test_decode_roundtrips_to_five_decimals(): void
    {
        $points = [[51.70123, 5.70456], [51.71, 5.71], [-33.86785, 151.20732]];

        $this->assertEquals($points, RouteGeometry::decode(RouteGeometry::encode($points)));
    }

    public function test_decode_ignores_a_truncated_string(): void
    {
        $encoded = RouteGeometry::encode([[10.0, 20.0], [11.0, 21.0]]);

        // Een halve eerste coördinaat levert niets op; een half tweede punt valt weg.
        $this->assertCount(0, RouteGeometry::decode(substr($encoded, 0, 3)));
        $this->assertCount(1, RouteGeometry::decode(substr($encoded, 0, -2)));
    }

    public function test_simplify_caps_the_point_count_and_keeps_both_ends(): void
    {
        // Een slingerend pad van 2000 punten.
        $points = [];
        for ($i = 0; $i < 2000; $i++) {
            $points[] = [46.0 + $i * 0.0001, 7.0 + sin($i / 15) * 0.002];
        }

        $simple = RouteGeometry::simplify($points, 300);

        $this->assertLessThanOrEqual(300, count($simple));
        $this->assertGreaterThan(20, count($simple));
        $this->assertEquals($points[0], $simple[0]);
        $this->assertEquals($points[1999], $simple[count($simple) - 1]);
    }

    public function test_simplify_leaves_short_paths_alone(): void
    {
        $points = [[1.0, 1.0], [1.1, 1.1], [1.2, 1.0]];

        $this->assertSame($points, RouteGeometry::simplify($points, 600));
    }

    public function test_bounds_of_points_and_of_locations_with_via(): void
    {
        $this->assertSame([1.0, 2.0, 3.0, 4.0], RouteGeometry::bounds([[1, 4], [3, 2], [2, 3]]));
        $this->assertNull(RouteGeometry::bounds([]));

        $locations = [
            ['lat' => 51.0, 'lon' => 5.0, 'via' => [['lat' => 51.5, 'lon' => 4.5]]],
            ['lat' => 51.2, 'lon' => 5.2],
            ['title' => 'zonder coördinaten'],
        ];
        $this->assertSame([51.0, 4.5, 51.5, 5.2], RouteGeometry::boundsOfLocations($locations));
    }

    public function test_locations_hash_follows_the_waypoints_only(): void
    {
        $a = [['lat' => 51.123456, 'lon' => 5.1, 'title' => 'A'], ['lat' => 51.2, 'lon' => 5.2]];
        $sameWithOtherFields = [['lat' => 51.123459, 'lon' => 5.1, 'title' => 'Andere naam', 'no' => 9], ['lat' => 51.2, 'lon' => 5.2]];
        $moved = [['lat' => 51.123456, 'lon' => 5.1], ['lat' => 51.3, 'lon' => 5.2]];

        $this->assertSame(RouteGeometry::locationsHash($a), RouteGeometry::locationsHash($sameWithOtherFields));
        $this->assertNotSame(RouteGeometry::locationsHash($a), RouteGeometry::locationsHash($moved));
    }

    public function test_compact_profile_drops_bad_pairs_sorts_and_caps(): void
    {
        $pairs = [[300, 120.4], ['x', 5], [0, 100.6], [100, 110], [null, 3]];

        $this->assertSame([[0, 101], [100, 110], [300, 120]], RouteGeometry::compactProfile($pairs));

        $many = [];
        for ($i = 0; $i < 1000; $i++) {
            $many[] = [$i * 10, 500 + $i % 7];
        }
        $capped = RouteGeometry::compactProfile($many, 150);
        $this->assertCount(150, $capped);
        $this->assertSame([0, 500], $capped[0]);
        $this->assertSame(9990, $capped[149][0]);
    }

    public function test_ascent_and_descent_ignore_noise_below_the_threshold(): void
    {
        // Ruis van ±1 m telt niet; een echte klim van 100 m en afdaling van 40 m wel.
        $profile = [[0, 1000], [100, 1001], [200, 1000], [300, 1100], [400, 1099], [500, 1060]];

        $this->assertSame([100, 40], RouteGeometry::ascentDescent($profile));
        $this->assertSame([0, 0], RouteGeometry::ascentDescent([[0, 10]]));
    }
}
