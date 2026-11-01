<?php

namespace App\Services;

use App\Http\Controllers\GeoProxyController;
use App\Models\TrailRoute;
use App\Support\TrailRoutePresenter;
use Illuminate\Support\Facades\Cache;

/**
 * Extra's voor de routedetail-pagina van een OSM-route: wegtypen, ondergrond en
 * hoogtepunten langs de route. De trail_routes-tabel bewaart alleen de geometrie;
 * de tags van de wegen en de POI's eromheen halen we één keer bij Overpass op
 * (via de OSM-relatie-id) en bewaren we 30 dagen. Mislukt Overpass, dan geven
 * we leeg terug met `pending`, zonder te cachen: de pagina toont dan niets en
 * probeert het bij de volgende keer opnieuw.
 */
class TrailRouteExtrasService
{
    private const CACHE_DAYS = 30;
    private const POI_RADIUS_M = 120;
    private const MAX_HIGHLIGHTS = 12;

    /** highway=* → groep die de app vertaalt (routeDiscover.wt_<groep>). */
    private const WAY_GROUPS = [
        'path' => 'path', 'footway' => 'footway', 'pedestrian' => 'footway', 'steps' => 'steps',
        'track' => 'track', 'bridleway' => 'bridleway', 'cycleway' => 'cycleway',
        'residential' => 'street', 'living_street' => 'street', 'service' => 'street',
        'unclassified' => 'road', 'tertiary' => 'road', 'tertiary_link' => 'road', 'secondary' => 'road',
        'secondary_link' => 'road', 'primary' => 'road', 'primary_link' => 'road', 'trunk' => 'road', 'trunk_link' => 'road',
    ];

    /** surface=* → groep (routeDiscover.sf_<groep>). */
    private const SURFACE_GROUPS = [
        'asphalt' => 'asphalt', 'chipseal' => 'asphalt',
        'paved' => 'paved', 'concrete' => 'paved', 'concrete:plates' => 'paved', 'concrete:lanes' => 'paved',
        'paving_stones' => 'paved', 'sett' => 'paved', 'cobblestone' => 'paved', 'unhewn_cobblestone' => 'paved', 'bricks' => 'paved',
        'gravel' => 'gravel', 'fine_gravel' => 'gravel', 'compacted' => 'gravel', 'pebblestone' => 'gravel', 'shells' => 'gravel',
        'unpaved' => 'natural', 'ground' => 'natural', 'dirt' => 'natural', 'earth' => 'natural', 'grass' => 'natural',
        'mud' => 'natural', 'wood' => 'natural', 'woodchips' => 'natural', 'grass_paver' => 'natural',
        'sand' => 'sand',
    ];

    public static function extras(TrailRoute $t): array
    {
        // Alleen een in OSM gemapte route heeft een relatie om te bevragen. Een gegenereerd rondje
        // geeft niets terug (niet "pending"): de app leidt wegtypes dan zelf van het pad af.
        if ($t->source !== 'osm') {
            return ['waytypes' => [], 'surfaces' => [], 'highlights' => [], 'total_m' => 0];
        }

        $key = 'trail-extras:v1:' . $t->id;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = self::compute($t);
        if (empty($result['pending'])) {
            Cache::put($key, $result, now()->addDays(self::CACHE_DAYS));
        }

        return $result;
    }

    private static function compute(TrailRoute $t): array
    {
        $id = (int) $t->source_id;
        $r = self::POI_RADIUS_M;
        $query = "[out:json][timeout:40];rel({$id});way(r)->.w;.w out geom;"
            . "(node(around.w:{$r})[name][tourism~\"^(viewpoint|attraction|museum|artwork|picnic_site|information)$\"];"
            . "node(around.w:{$r})[name][historic];"
            . "node(around.w:{$r})[name][natural~\"^(peak|spring|cave_entrance|tree|water)$\"];"
            . "node(around.w:{$r})[name][amenity~\"^(cafe|restaurant|drinking_water|shelter|bench|place_of_worship)$\"];"
            . "node(around.w:{$r})[name][man_made~\"^(windmill|watermill|lighthouse|tower)$\"];);out 80;";

        // Zelfde cache-sleutel als de Overpass-proxy (GeoProxyController::overpass):
        // één antwoord per query, hoe het ook is opgehaald.
        $qKey = 'geo:overpass:' . sha1($query);
        $json = Cache::get($qKey);
        if (! is_array($json)) {
            $json = GeoProxyController::overpassUpstream($query);
            if (empty($json['error']) && isset($json['elements'])) {
                Cache::put($qKey, $json, now()->addDays(7));
            }
        }
        if (! empty($json['error']) || ! isset($json['elements'])) {
            return ['waytypes' => [], 'surfaces' => [], 'highlights' => [], 'total_m' => 0, 'pending' => true];
        }

        $ways = [];
        $surfaces = [];
        $totalM = 0;
        $pois = [];
        foreach ($json['elements'] as $el) {
            if (($el['type'] ?? '') === 'way' && ! empty($el['geometry'])) {
                $m = self::lengthM($el['geometry']);
                if ($m <= 0) {
                    continue;
                }
                $totalM += $m;
                $hw = $el['tags']['highway'] ?? '';
                $wg = self::WAY_GROUPS[$hw] ?? ($hw ? 'other' : 'unknown');
                $ways[$wg] = ($ways[$wg] ?? 0) + $m;
                $sf = $el['tags']['surface'] ?? '';
                $sg = self::SURFACE_GROUPS[$sf] ?? ($sf ? 'other' : 'unknown');
                $surfaces[$sg] = ($surfaces[$sg] ?? 0) + $m;
            } elseif (($el['type'] ?? '') === 'node' && isset($el['lat'], $el['lon'])) {
                $pois[] = $el;
            }
        }

        $line = array_merge(...(TrailRoutePresenter::segments($t) ?: [[]]));
        $cum = self::cumulative($line);
        $highlights = [];
        foreach ($pois as $p) {
            $tags = $p['tags'] ?? [];
            [$idx, $dist] = self::nearest($line, (float) $p['lat'], (float) $p['lon']);
            if ($idx < 0 || $dist > self::POI_RADIUS_M * 1.5) {
                continue;
            }
            $highlights[] = [
                'id' => (int) $p['id'],
                'name' => $tags['name'],
                'kind' => self::poiKind($tags),
                'lat' => (float) $p['lat'],
                'lon' => (float) $p['lon'],
                'at_m' => (int) round($cum[$idx] ?? 0),
            ];
        }
        usort($highlights, fn ($a, $b) => $a['at_m'] <=> $b['at_m']);
        // Dubbele namen (zelfde naam op meerdere nodes) één keer tonen.
        $seen = [];
        $highlights = array_values(array_filter($highlights, function ($h) use (&$seen) {
            $k = mb_strtolower($h['name']);
            if (isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;

            return true;
        }));
        $highlights = array_slice($highlights, 0, self::MAX_HIGHLIGHTS);

        return [
            'waytypes' => self::rows($ways),
            'surfaces' => self::rows($surfaces),
            'highlights' => $highlights,
            'total_m' => (int) round($totalM),
        ];
    }

    private static function poiKind(array $tags): string
    {
        if (isset($tags['tourism'])) {
            return $tags['tourism'] === 'viewpoint' ? 'viewpoint' : ($tags['tourism'] === 'artwork' ? 'artwork' : 'tourism');
        }
        if (isset($tags['historic'])) {
            return 'historic';
        }
        if (isset($tags['natural'])) {
            return $tags['natural'] === 'peak' ? 'peak' : 'nature';
        }
        if (isset($tags['man_made'])) {
            return 'landmark';
        }
        if (isset($tags['amenity'])) {
            return in_array($tags['amenity'], ['cafe', 'restaurant'], true) ? 'food' : 'amenity';
        }

        return 'poi';
    }

    /** [['key' => groep, 'meters' => n], …] aflopend op lengte. */
    private static function rows(array $byGroup): array
    {
        arsort($byGroup);
        $out = [];
        foreach ($byGroup as $k => $m) {
            $out[] = ['key' => $k, 'meters' => (int) round($m)];
        }

        return $out;
    }

    private static function lengthM(array $geometry): float
    {
        $m = 0;
        for ($i = 1, $n = count($geometry); $i < $n; $i++) {
            $a = $geometry[$i - 1];
            $b = $geometry[$i];
            if (isset($a['lat'], $a['lon'], $b['lat'], $b['lon'])) {
                $m += self::haversine((float) $a['lat'], (float) $a['lon'], (float) $b['lat'], (float) $b['lon']);
            }
        }

        return $m;
    }

    /** Afgelegde afstand tot elk punt van een [lat, lon]-lijn. */
    private static function cumulative(array $line): array
    {
        $cum = [0.0];
        for ($i = 1, $n = count($line); $i < $n; $i++) {
            $cum[$i] = $cum[$i - 1] + self::haversine($line[$i - 1][0], $line[$i - 1][1], $line[$i][0], $line[$i][1]);
        }

        return $cum;
    }

    /** @return array{0:int,1:float} index van het dichtstbijzijnde lijnpunt + afstand (m) */
    private static function nearest(array $line, float $lat, float $lon): array
    {
        $best = -1;
        $bestD = INF;
        // Grove voorselectie op graden (snel), pas daarna haversine.
        foreach ($line as $i => [$plat, $plon]) {
            $d = abs($plat - $lat) + abs($plon - $lon) * cos(deg2rad($lat));
            if ($d < $bestD) {
                $bestD = $d;
                $best = $i;
            }
        }
        if ($best < 0) {
            return [-1, INF];
        }

        return [$best, self::haversine($line[$best][0], $line[$best][1], $lat, $lon)];
    }

    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
    }
}
