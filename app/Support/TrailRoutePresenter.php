<?php

namespace App\Support;

use App\Models\TrailRoute;
use Illuminate\Support\Collection;

/**
 * Hoe een geïmporteerde OpenStreetMap-route (TrailRoute) in "Routes ontdekken"
 * verschijnt: dezelfde velden als PublicRoutePresenter (zodat het paneel ze
 * gelijk behandelt), maar zonder maker en zonder wat OSM niet kent
 * (moeilijkheid, hoogtemeters).
 *
 * Net als daar een WHITELIST: het GPX-pad en het OSM-ID bereiken de client niet.
 */
class TrailRoutePresenter
{
    /** `cloned_from_type` van een routekaart die uit een OSM-route is gemaakt. */
    public const CLONE_TYPE = 'trail_route';

    /** TrailRoute.type → sport in de API. */
    private const SPORTS = ['wandelen' => 'hike', 'fietsen' => 'bike', 'mtb' => 'mtb', 'hardlopen' => 'run'];

    /** Looptempo (km/u) voor de duur-filters, gelijk aan RouteDiscoveryFilters. */
    private const SPEED_KMH = ['hike' => 5, 'run' => 9, 'bike' => 17, 'mtb' => 12];

    public static function sport(TrailRoute $t): string
    {
        return self::SPORTS[$t->type] ?? 'hike';
    }

    public static function speedKmh(TrailRoute $t): int
    {
        return self::SPEED_KMH[self::sport($t)] ?? 5;
    }

    /** API-sport (hike|bike|mtb|run) → TrailRoute.type; null als OSM daar niets voor importeert. */
    public static function typeForSport(string $sport): ?string
    {
        $type = array_search($sport, self::SPORTS, true);

        return $type === false ? null : $type;
    }

    /**
     * @return array<int, array<int, array{0: float, 1: float}>>  segmenten als [lat, lon]-lijnen
     */
    public static function segments(TrailRoute $t): array
    {
        return self::segmentsOf($t->geometry);
    }

    /**
     * @param  array<string, mixed>|null  $geometry  GeoJSON MultiLineString ([lon, lat]-paren)
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    public static function segmentsOf(?array $geometry): array
    {
        $out = [];
        foreach ($geometry['coordinates'] ?? [] as $segment) {
            $line = [];
            foreach ($segment as $c) {
                if (isset($c[0], $c[1])) {
                    $line[] = [(float) $c[1], (float) $c[0]];
                }
            }
            if (count($line) >= 2) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * De lijn voor in de lijst en op de kaart: het langste segment, uitgedund tot
     * PREVIEW_POINTS [lat, lon]-punten. Wordt bij opslaan in `preview`/`point_count`
     * bewaard (zie TrailRoute), zodat een lijst nooit de volledige geometry hoeft te laden.
     *
     * @param  array<string, mixed>|null  $geometry
     * @return array{preview: array<int, array{0: float, 1: float}>, point_count: int}
     */
    public static function previewFor(?array $geometry): array
    {
        $segments = self::segmentsOf($geometry);
        $main = $segments ? array_reduce($segments, fn ($best, $s) => count($s) > count($best ?? []) ? $s : $best) : [];

        return [
            'preview' => RouteGeometry::sample($main, PublicRoutePresenter::PREVIEW_POINTS),
            'point_count' => count($main),
        ];
    }

    /**
     * Routes zonder bewaarde preview (rijen die buiten Eloquent zijn aangemaakt) krijgen
     * hem hier alsnog, uit een aparte geometry-query voor alleen die rijen. Niets wordt opgeslagen.
     *
     * @param  Collection<int, TrailRoute>  $routes  zonder geometry geladen
     */
    public static function fillMissingPreviews(Collection $routes): void
    {
        $missing = $routes->filter(fn (TrailRoute $t) => $t->getAttribute('preview') === null);
        if ($missing->isEmpty()) {
            return;
        }

        $geometries = TrailRoute::whereIn('id', $missing->pluck('id')->all())->get(['id', 'geometry'])->keyBy('id');
        foreach ($missing as $t) {
            $computed = self::previewFor($geometries->get($t->id)?->geometry);
            $t->setAttribute('preview', $computed['preview']);
            $t->setAttribute('point_count', $computed['point_count']);
        }
    }

    /** @param  Collection<int, TrailRoute>  $routes */
    public static function summaries(Collection $routes): array
    {
        $clones = PublicRoutePresenter::cloneCounts(self::CLONE_TYPE, $routes->pluck('id')->all());

        return $routes->map(function (TrailRoute $t) use ($clones) {
            return [
                'preview' => $t->preview ?? [],
                'point_count' => (int) $t->point_count,
                'clones' => $clones[(string) $t->id] ?? 0,
            ] + self::common($t);
        })->values()->all();
    }

    public static function detail(TrailRoute $t): array
    {
        $line = array_merge(...(self::segments($t) ?: [[]]));

        return [
            'speed' => self::speedKmh($t),
            'declination' => null,
            'total_time' => 0,
            'locations' => PublicRoutePresenter::cleanLocations([[
                'lat' => (float) $t->start_lat, 'lon' => (float) $t->start_lon, 'title' => 'Start',
            ]]),
            // Gesnapt pad (encoded polyline, precisie 5). Segmenten achter elkaar:
            // een gat in de OSM-relatie wordt zo met een rechte lijn overbrugd.
            'path' => $line ? RouteGeometry::encode(RouteGeometry::simplify($line)) : null,
            'elevation_profile' => null,
            'source_ref' => null,
            'clones' => PublicRoutePresenter::cloneCounts(self::CLONE_TYPE, [$t->id])[(string) $t->id] ?? 0,
        ] + self::common($t);
    }

    /**
     * Het pad als waypoints voor een eigen routekaart: begin, een paar punten
     * onderweg en einde. Het echte pad gaat apart mee als polyline.
     *
     * @return array<int, array{lat: float, lon: float, title: string}>
     */
    public static function waypoints(TrailRoute $t): array
    {
        $line = array_merge(...(self::segments($t) ?: [[]]));
        if (count($line) < 2) {
            return [];
        }

        // Eén tussenpunt per ± 3 km, hooguit 6.
        $mid = max(0, min(6, (int) round((float) $t->distance_km / 3)));
        $points = RouteGeometry::sample($line, $mid + 2);

        return array_map(fn (array $p, int $i) => [
            'lat' => (float) $p[0], 'lon' => (float) $p[1], 'title' => $i === 0 ? 'Start' : '',
        ], $points, array_keys($points));
    }

    /** Velden die in lijst én detail hetzelfde zijn. */
    private static function common(TrailRoute $t): array
    {
        return [
            'kind' => 'route',
            // Voorvoegsel: kan nooit botsen met het uuid van een routekaart.
            'id' => 'osm-' . $t->id,
            'slug' => $t->slug,
            'title' => $t->name,
            'color' => null,
            'total_distance' => round((float) $t->distance_km * 1000),
            'total_elevation' => 0,
            'published_at' => $t->created_at?->toIso8601String(),
            'photos' => [],
            'sport' => self::sport($t),
            'difficulty' => null,
            'ascent_m' => null,
            'descent_m' => null,
            'has_path' => true,
            'bbox' => [(float) $t->min_lat, (float) $t->min_lon, (float) $t->max_lat, (float) $t->max_lon],
            'is_loop' => (bool) $t->is_loop,
            'source' => 'osm',
            'attribution' => $t->attribution,
            'source_url' => $t->source_url,
            'operator' => $t->operator,
            // `source` hierboven is de API-familie (een route uit de catalogus), `generated` zegt of
            // het pad in OSM gemapt is of door MilMap over OSM-paden is gelegd.
            'generated' => $t->source === 'generated',
            // Geen gebruikersprofiel: OSM, of voor een gegenereerd rondje MilMap, is de bron.
            'author' => ['handle' => null, 'name' => $t->source === 'generated' ? 'MilMap' : 'OpenStreetMap', 'avatar_url' => null],
        ];
    }
}
