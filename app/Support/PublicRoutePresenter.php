<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\PublicProfile;
use App\Models\RouteMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bepaalt wat een vreemde van een gepubliceerde route te zien krijgt.
 *
 * Alles hier is een WHITELIST: een veld dat niet expliciet wordt overgenomen
 * (eenheid, roepnaam, bijlagen, notities, foto's, missie-koppeling, eigenaar-id,
 * Garmin-status …) bereikt een andere gebruiker nooit.
 */
class PublicRoutePresenter
{
    public const PREVIEW_POINTS = 40;

    public const MAX_LOCATIONS = 200;

    /** @param  Collection<int, RouteMap>  $routes */
    /** SEO-vriendelijke naam: "avervoorderpad-a319f662" (titel + eerste 8 tekens van het id). */
    public static function routeSlug(RouteMap $r): string
    {
        $base = \Illuminate\Support\Str::slug((string) $r->title) ?: 'route';

        return \Illuminate\Support\Str::limit($base, 70, '') . '-' . substr((string) $r->id, 0, 8);
    }

    public static function routeSummaries(Collection $routes): array
    {
        $clones = self::cloneCounts('route_map', $routes->pluck('id')->all());

        return $routes->map(function (RouteMap $r) use ($clones) {
            $locs = self::cleanLocations($r->locations ?? []);

            return [
                'kind' => 'route',
                'id' => $r->id,
                'slug' => self::routeSlug($r),
                'title' => $r->title,
                'color' => $r->color,
                'total_distance' => (float) $r->total_distance,
                'total_elevation' => (float) $r->total_elevation,
                'point_count' => count($locs),
                // Het gesnapte pad als dat er is; anders de lijn langs de waypoints.
                'preview' => self::sample(self::previewLine($r, $locs), self::PREVIEW_POINTS),
                'clones' => $clones[$r->id] ?? 0,
                'published_at' => $r->published_at?->toIso8601String(),
                'photos' => self::modelPhotos($r),
            ] + self::discoveryFields($r);
        })->values()->all();
    }

    /**
     * Wat een route vindbaar en te beoordelen maakt: sport, stijgen/dalen,
     * bron en omhullende. Zonder pad blijft `has_path` false.
     */
    public static function discoveryFields(RouteMap $r): array
    {
        $bbox = $r->bbox_min_lat === null ? null : [
            (float) $r->bbox_min_lat, (float) $r->bbox_min_lon,
            (float) $r->bbox_max_lat, (float) $r->bbox_max_lon,
        ];

        return [
            'sport' => $r->sport,
            'difficulty' => $r->difficulty,
            'ascent_m' => $r->ascent_m,
            'descent_m' => $r->descent_m,
            'has_path' => filled($r->path_polyline),
            'bbox' => $bbox,
            'source' => $r->source ?: 'milmap',
            'attribution' => $r->attribution,
        ];
    }

    /** [lat, lon]-lijn voor een voorbeeldje: het opgeslagen pad, anders de waypoints (+ buigpunten). */
    private static function previewLine(RouteMap $r, array $locs): array
    {
        if (filled($r->path_polyline)) {
            $path = RouteGeometry::decode((string) $r->path_polyline);
            if (count($path) >= 2) {
                return $path;
            }
        }

        return self::routeLine($locs);
    }

    public static function routeDetail(RouteMap $r): array
    {
        $locs = self::cleanLocations($r->locations ?? []);

        return [
            'kind' => 'route',
            'id' => $r->id,
            'slug' => self::routeSlug($r),
            'title' => $r->title,
            'color' => $r->color,
            'speed' => $r->speed,
            'declination' => $r->declination,
            'total_distance' => (float) $r->total_distance,
            'total_elevation' => (float) $r->total_elevation,
            'total_time' => (int) $r->total_time,
            'locations' => $locs,
            // Gesnapt pad (encoded polyline, precisie 5) en hoogteprofiel [[afstand_m, hoogte_m], …].
            'path' => filled($r->path_polyline) ? (string) $r->path_polyline : null,
            'elevation_profile' => $r->elevation_profile ?: null,
            'source_ref' => $r->source_ref,
            'clones' => self::cloneCounts('route_map', [$r->id])[$r->id] ?? 0,
            'published_at' => $r->published_at?->toIso8601String(),
            'photos' => self::modelPhotos($r),
        ] + self::discoveryFields($r);
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return array<int, array>  Tracks die na het wegknippen te kort zijn om iets te tonen vallen weg.
     */
    public static function trackSummaries(Collection $activities, PublicProfile $profile): array
    {
        $clones = self::cloneCounts('activity', $activities->pluck('id')->map(fn ($id) => (string) $id)->all());
        $out = [];

        foreach ($activities as $a) {
            $points = self::publicPoints($a, $profile);
            if (count($points) < 2) {
                continue;
            }
            $out[] = [
                'kind' => 'track',
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'started_on' => $a->started_at?->toDateString(),
                'distance_m' => (int) $a->distance_m,
                'moving_time_s' => (int) $a->moving_time_s,
                'elevation_gain_m' => (int) $a->elevation_gain_m,
                'elevation_loss_m' => self::elevationLoss($points),
                'avg_speed_kmh' => $a->avg_speed_kmh,
                'preview' => self::sample(array_map(fn ($p) => [$p['lat'], $p['lon']], $points), self::PREVIEW_POINTS),
                'clones' => $clones[(string) $a->id] ?? 0,
                'published_at' => $a->published_at?->toIso8601String(),
                'photos' => self::modelPhotos($a),
            ];
        }

        return $out;
    }

    /**
     * Eén feed, nieuwste eerst. Datum = dag van de activiteit (tracks) of van
     * publicatie (routes); nooit een tijdstip.
     */
    public static function timeline(array $routes, array $tracks): array
    {
        $items = [];
        foreach ($routes as $r) {
            $items[] = $r + ['date' => substr((string) ($r['published_at'] ?? ''), 0, 10) ?: null];
        }
        foreach ($tracks as $t) {
            $items[] = $t + ['date' => $t['started_on'] ?? substr((string) ($t['published_at'] ?? ''), 0, 10) ?: null];
        }
        usort($items, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']) ?: ($b['id'] <=> $a['id']));

        return $items;
    }

    public static function trackDetail(Activity $a, PublicProfile $profile): ?array
    {
        $points = self::publicPoints($a, $profile);
        if (count($points) < 2) {
            return null;
        }

        // `t` is een absolute tijdstempel (ms). Relatief maken aan het eerste
        // openbare punt: grafiek en flyover gebruiken alleen verschillen, en zo
        // is niet af te lezen hoe laat iemand op pad was.
        $t0 = is_numeric($points[0]['t'] ?? null) ? (float) $points[0]['t'] : null;

        return [
            'kind' => 'track',
            'id' => $a->id,
            'title' => $a->title,
            'type' => $a->type,
            // Alleen de datum, geen tijdstip (patroon van wanneer iemand traint).
            'started_on' => $a->started_at?->toDateString(),
            'distance_m' => (int) $a->distance_m,
            'moving_time_s' => (int) $a->moving_time_s,
            'elapsed_time_s' => (int) $a->elapsed_time_s,
            'elevation_gain_m' => (int) $a->elevation_gain_m,
            'elevation_loss_m' => self::elevationLoss($points),
            'avg_speed_kmh' => $a->avg_speed_kmh,
            'avg_pace_s_per_km' => $a->avg_pace_s_per_km,
            // Alleen wat de kaart + hoogte-/snelheidsgrafiek nodig hebben.
            'points' => array_map(fn ($p) => array_filter([
                'lat' => (float) $p['lat'],
                'lon' => (float) $p['lon'],
                't' => $t0 !== null && is_numeric($p['t'] ?? null) ? (float) $p['t'] - $t0 : null,
                'ele' => $p['ele'] ?? null,
                'speed' => $p['speed'] ?? null,
            ], fn ($v) => $v !== null), $points),
            'clones' => self::cloneCounts('activity', [(string) $a->id])[(string) $a->id] ?? 0,
            'published_at' => $a->published_at?->toIso8601String(),
            'photos' => self::modelPhotos($a),
        ];
    }

    private static function photos(iterable $photos): array
    {
        return collect($photos)->take(6)->map(fn ($photo) => [
            'id' => $photo->id,
            'url' => $photo->url,
        ])->values()->all();
    }

    private static function modelPhotos(Activity|RouteMap $model): array
    {
        return $model->relationLoaded('photos') ? self::photos($model->getRelation('photos')) : [];
    }

    private static function elevationLoss(array $points): int
    {
        $loss = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if (! is_numeric($points[$i - 1]['ele'] ?? null) || ! is_numeric($points[$i]['ele'] ?? null)) {
                continue;
            }
            $delta = (float) $points[$i - 1]['ele'] - (float) $points[$i]['ele'];
            if ($delta > 1) $loss += $delta;
        }

        return (int) round($loss);
    }

    /**
     * De punten die anderen mogen zien: bij `hide_endpoints` zijn de eerste en
     * laatste ENDPOINT_TRIM_M meter (gemeten langs het pad) weggeknipt, zodat
     * een start/finish bij iemands huis niet af te lezen is.
     *
     * @return array<int, array{lat: float, lon: float}>
     */
    public static function publicPoints(Activity $a, PublicProfile $profile): array
    {
        $points = array_values(array_filter(
            (array) ($a->points ?? []),
            fn ($p) => is_array($p) && isset($p['lat'], $p['lon'])
        ));

        return $profile->hide_endpoints
            ? self::trimEndpoints($points, PublicProfile::ENDPOINT_TRIM_M)
            : $points;
    }

    public static function trimEndpoints(array $points, float $meters): array
    {
        $n = count($points);
        if ($n < 2) {
            return [];
        }

        // Cumulatieve afstand langs het pad.
        $cum = [0.0];
        for ($i = 1; $i < $n; $i++) {
            $cum[$i] = $cum[$i - 1] + self::haversine(
                (float) $points[$i - 1]['lat'], (float) $points[$i - 1]['lon'],
                (float) $points[$i]['lat'], (float) $points[$i]['lon']
            );
        }
        $total = $cum[$n - 1];

        // Te kort om aan beide kanten te knippen en nog iets over te houden.
        if ($total <= $meters * 2 + 50) {
            return [];
        }

        return array_values(array_filter(
            $points,
            fn ($_, $i) => $cum[$i] >= $meters && ($total - $cum[$i]) >= $meters,
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * Locaties van een routekaart, teruggebracht tot de velden die een andere
     * gebruiker nodig heeft om de route te tonen of over te nemen.
     */
    public static function cleanLocations(array $locations): array
    {
        $out = [];
        foreach (array_slice(array_values($locations), 0, self::MAX_LOCATIONS) as $loc) {
            if (! is_array($loc) || ! isset($loc['lat'], $loc['lon'])
                || ! is_numeric($loc['lat']) || ! is_numeric($loc['lon'])) {
                continue;
            }
            $out[] = [
                'no' => count($out) + 1,
                'lat' => (float) $loc['lat'],
                'lon' => (float) $loc['lon'],
                'mgrs' => isset($loc['mgrs']) && is_string($loc['mgrs']) ? mb_substr($loc['mgrs'], 0, 24) : null,
                'elevation' => isset($loc['elevation']) && is_numeric($loc['elevation']) ? (float) $loc['elevation'] : null,
                'elevationGain' => isset($loc['elevationGain']) && is_numeric($loc['elevationGain']) ? (float) $loc['elevationGain'] : null,
                'title' => isset($loc['title']) && is_string($loc['title']) ? mb_substr($loc['title'], 0, 120) : '',
                'checkpoint' => [
                    'enabled' => (bool) data_get($loc, 'checkpoint.enabled', false),
                    'label' => mb_substr((string) data_get($loc, 'checkpoint.label', ''), 0, 60),
                ],
                'pause' => [
                    'type' => data_get($loc, 'pause.type'),
                    'custom' => (int) data_get($loc, 'pause.custom', 0),
                ],
                // Buigpunten van de leg naar het volgende punt ({lon, lat}).
                'via' => self::cleanVia($loc['via'] ?? []),
            ];
        }

        return $out;
    }

    private static function cleanVia(mixed $via): array
    {
        if (! is_array($via)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($via), 0, 100) as $v) {
            if (is_array($v) && isset($v['lat'], $v['lon']) && is_numeric($v['lat']) && is_numeric($v['lon'])) {
                $out[] = ['lon' => (float) $v['lon'], 'lat' => (float) $v['lat']];
            }
        }

        return $out;
    }

    /** De lijn van een route als [lat, lon]-paren, inclusief de buigpunten (via) van elke leg. */
    public static function routeLine(array $locs): array
    {
        $line = [];
        foreach ($locs as $l) {
            $line[] = [$l['lat'], $l['lon']];
            foreach ($l['via'] as $v) {
                $line[] = [$v['lat'], $v['lon']];
            }
        }

        return $line;
    }

    /** Gelijkmatig uitdunnen tot hooguit $max punten, begin en eind altijd behouden. */
    public static function sample(array $items, int $max): array
    {
        $n = count($items);
        if ($n <= $max) {
            return array_values($items);
        }
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $items[(int) round($i * ($n - 1) / ($max - 1))];
        }

        return $out;
    }

    /** Door hoeveel verschillende mensen is elk item gekloond? [id => aantal] */
    public static function cloneCounts(string $type, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('route_maps')
            ->where('cloned_from_type', $type)
            ->whereIn('cloned_from_id', array_map('strval', $ids))
            ->whereNull('deleted_at')
            ->selectRaw('cloned_from_id, COUNT(DISTINCT owner_id) as aantal')
            ->groupBy('cloned_from_id')
            ->pluck('aantal', 'cloned_from_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
