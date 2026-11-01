<?php

namespace Tests\Unit;

use App\Support\KakoAppRules as R;
use Tests\TestCase;

/** Pure rekenregels van de KAKO app-modus (geen database). */
class KakoAppRulesTest extends TestCase
{
    public function test_config_defaults_and_clamping(): void
    {
        $c = R::config(null);
        $this->assertSame('optional', $c['tracking']['mode']);
        $this->assertSame(15, $c['tracking']['interval_s']);
        $this->assertSame(30, $c['tracking']['retention_days']);
        $this->assertSame('radius', $c['checkin']['verification']);
        $this->assertSame(30, $c['checkin']['radius_m']);
        $this->assertSame(['manual', 'qr', 'nfc'], $c['checkin']['methods']);
        $this->assertNull($c['checkin']['max_attempts']);
        $this->assertTrue($c['leave_allowed']);

        $c = R::config(['appMode' => ['tracking' => ['mode' => 'wat', 'retentionDays' => 9999, 'intervalS' => 1], 'checkin' => ['methods' => ['gps', 'qr'], 'radiusM' => 1, 'maxAttempts' => 0, 'verification' => 'record']]]);
        $this->assertSame('optional', $c['tracking']['mode']);
        $this->assertSame(90, $c['tracking']['retention_days'], 'bewaartermijn nooit boven 90 dagen');
        $this->assertSame(5, $c['tracking']['interval_s']);
        $this->assertSame(['qr'], $c['checkin']['methods']);
        $this->assertSame(5, $c['checkin']['radius_m']);
        $this->assertNull($c['checkin']['max_attempts']);
        $this->assertSame('record', $c['checkin']['verification']);

        $this->assertSame(7, R::config(['appMode' => ['tracking' => ['retentionDays' => 1]]])['tracking']['retention_days']);
        $this->assertSame(['manual'], R::config(['appMode' => ['checkin' => ['methods' => []]]])['checkin']['methods']);
    }

    public function test_restrictions_true_means_allowed_and_missing_means_allowed(): void
    {
        $this->assertSame(['navigation' => true, 'tools' => true, 'locationSharing' => true, 'sidebar' => true, 'routemap' => true, 'editing' => true], R::restrictions(null));
        $r = R::restrictions(['restrictions' => ['navigation' => false, 'editing' => 0]]);
        $this->assertFalse($r['navigation']);
        $this->assertFalse($r['editing']);
        $this->assertTrue($r['tools']);
    }

    public function test_distance_and_bearing(): void
    {
        $this->assertEqualsWithDelta(0, R::distanceM(51.5, 5.3, 51.5, 5.3), 0.001);
        // 0,01° breedte ≈ 1112 m
        $this->assertEqualsWithDelta(1112, R::distanceM(51.5, 5.3, 51.51, 5.3), 5);
        $this->assertSame(0, R::bearingDeg(51.5, 5.3, 51.6, 5.3));
        $this->assertSame(180, R::bearingDeg(51.6, 5.3, 51.5, 5.3));
        $this->assertSame(90, R::bearingDeg(0, 0, 0, 1));
        $this->assertSame(270, R::bearingDeg(0, 1, 0, 0));
        $this->assertEqualsWithDelta(2224, R::polylineM([[51.5, 5.3], [51.51, 5.3], [51.52, 5.3]]), 10);
    }

    public function test_within_radius_forgives_gps_inaccuracy_up_to_25m(): void
    {
        $this->assertTrue(R::withinRadius(30, 0, 30));
        $this->assertFalse(R::withinRadius(31, 0, 30));
        $this->assertTrue(R::withinRadius(50, 25, 30), 'onnauwkeurigheid telt tot 25 m mee');
        $this->assertFalse(R::withinRadius(80, 200, 30), 'een slechte fix is geen vrijbrief');
    }

    public function test_route_parts_follow_the_code(): void
    {
        $this->assertSame([['code' => '1', 'kind' => 'course'], ['code' => 'A', 'kind' => 'paths']], R::routeParts('1A'));
        $this->assertSame([['code' => 'B', 'kind' => 'paths'], ['code' => '12', 'kind' => 'course']], R::routeParts('b12'));
        $this->assertSame([['code' => 'A', 'kind' => 'paths'], ['code' => '1', 'kind' => 'course']], R::routeParts('1A', 'paths_first'));
        $this->assertSame([['code' => '1', 'kind' => 'course'], ['code' => 'A', 'kind' => 'paths']], R::routeParts('A1', 'course_first'));
        $this->assertSame([['code' => '7', 'kind' => 'course']], R::routeParts('7'));
        $this->assertSame([], R::routeParts(null));
    }

    public function test_tag_tokens_are_stable_unique_and_versioned(): void
    {
        $t = R::tagToken('assign-1', 'p1', 1);
        $this->assertSame($t, R::tagToken('assign-1', 'p1', 1));
        $this->assertNotSame($t, R::tagToken('assign-1', 'p2', 1));
        $this->assertNotSame($t, R::tagToken('assign-2', 'p1', 1));
        $this->assertNotSame($t, R::tagToken('assign-1', 'p1', 2), 'tagVersion ophogen maakt gedrukte codes ongeldig');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]{22}$/', $t);
        $this->assertTrue(R::tagValid('assign-1', 'p1', 1, $t));
        $this->assertFalse(R::tagValid('assign-1', 'p1', 1, null));
        $this->assertFalse(R::tagValid('assign-1', 'p1', 1, ''));
        $this->assertFalse(R::tagValid('assign-1', 'p1', 2, $t));
    }

    public function test_legs_have_bearing_only_for_course_and_no_coordinates(): void
    {
        $locs = [
            ['id' => 'start', 'lat' => 51.5, 'lon' => 5.3, 'checkpoint' => ['label' => 'Start']],
            ['id' => 'p1', 'lat' => 51.51, 'lon' => 5.3, 'description' => 'bij de brug', 'checkpoint' => ['label' => 'Post 1']],
            ['id' => 'start', 'lat' => 51.5, 'lon' => 5.3, 'checkpoint' => ['label' => 'Finish']],
        ];
        $course = R::legs($locs, 'course', true);
        $this->assertCount(2, $course);
        $this->assertSame(0, $course[0]['bearing_deg']);
        $this->assertSame(180, $course[1]['bearing_deg']);
        $this->assertSame('finish', $course[1]['role']);
        $this->assertSame('bij de brug', $course[0]['description']);
        $this->assertEqualsWithDelta(1112, $course[0]['distance_m'], 5);
        $this->assertArrayNotHasKey('lat', $course[0]);

        $paths = R::legs($locs, 'paths', false);
        $this->assertNull($paths[0]['bearing_deg']);
        $this->assertNull($paths[0]['description'], 'omschrijving uit: ook niet in de app');

        // Een tussenpunt (via) telt mee in de afstand langs de paden.
        $locs[0]['via'] = [['lat' => 51.505, 'lon' => 5.31]];
        $this->assertGreaterThan($course[0]['distance_m'], R::legs($locs, 'paths', true)[0]['distance_m']);
    }
}
