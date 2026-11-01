<?php

namespace App\Http\Controllers;

use App\Models\PublicProfile;
use App\Models\RouteMap;
use App\Models\TrailRoute;
use App\Services\ConnectManagement;
use App\Support\PublicRoutePresenter;
use App\Support\TrailRoutePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Routes van andere gebruikers vinden op de kaart.
 *
 * Alleen routes die de maker zelf publiceerde (`is_public`) én waarvan het
 * openbare profiel aan staat zijn vindbaar. Wat er van een route terugkomt gaat
 * volledig door PublicRoutePresenter (een whitelist): dezelfde velden als op de
 * profielpagina, plus de naam/handle van de maker. Eigen routes komen er niet
 * in terug: dit is om die van anderen te ontdekken.
 */
class RouteDiscoveryController extends Controller
{
    /** Groter gebied dan dit (graden) wordt rond het midden ingekort, zodat één zoekopdracht klein blijft. */
    private const MAX_SPAN_DEG = 1.5;

    private const DEFAULT_RADIUS_KM = 25;

    private const DEFAULT_LIMIT = 30;

    /** Hoeveel kandidaten we uit de database halen voordat op afstand gesorteerd wordt. */
    private const CANDIDATES = 150;

    /**
     * Met `all=1`: zoveel routes (de dichtstbijzijnde) komen volledig terug, met preview, maker enz.
     * De rest alleen als marker ({id, slug, source, sport, lat, lon}), zodat uitzoomen een paar
     * honderd bytes per route kost in plaats van een paar kilobyte, en de server alleen voor deze
     * routes een samenvatting opbouwt.
     */
    private const FULL_ITEMS = 200;

    /** Hoeveel markers er hooguit naast de volledige routes komen (dichtstbijzijnde eerst). */
    private const MAX_MARKERS = 3000;

    /**
     * GET /api/v1/routes/discover?bbox=minLon,minLat,maxLon,maxLat
     * of  /api/v1/routes/discover?lat=&lon=&radius_km=
     * (optioneel: sport=hike|bike|mtb|run, limit=1..30)
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bbox' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_without:bbox'],
            'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_without:bbox'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'sport' => ['nullable', 'in:hike,bike,mtb,run'],
            'all' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::DEFAULT_LIMIT],
            'min_km' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'max_km' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'min_minutes' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'max_minutes' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'min_ascent' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'max_ascent' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'difficulty' => ['nullable', 'in:easy,moderate,hard'],
            'route_type' => ['nullable', 'in:loop,point_to_point'],
        ]);

        foreach (['km', 'minutes', 'ascent'] as $unit) {
            if (isset($data['min_'.$unit], $data['max_'.$unit]) && $data['min_'.$unit] > $data['max_'.$unit]) {
                throw \Illuminate\Validation\ValidationException::withMessages(['max_'.$unit => 'Het maximum moet minstens gelijk zijn aan het minimum.']);
            }
        }

        $all = (bool) ($data['all'] ?? false);
        [$minLat, $minLon, $maxLat, $maxLon] = ! empty($data['bbox'])
            ? $this->parseBbox($data['bbox'], $all)
            : $this->bboxAround((float) $data['lat'], (float) $data['lon'], (float) ($data['radius_km'] ?? self::DEFAULT_RADIUS_KM));

        $centerLat = ($minLat + $maxLat) / 2;
        $centerLon = ($minLon + $maxLon) / 2;

        // Ook zonder auth-middleware (de route is publiek leesbaar) kennen we de bezoeker aan zijn token:
        // `Auth::id()` / `$request->user()` gebruiken dan de standaard-guard en geven null.
        $viewerId = Auth::guard('sanctum')->id();

        $query = RouteMap::query()
            ->where('is_public', true)
            ->whereNull('connect_organization_id')
            ->when($viewerId !== null, fn ($q) => $q->where('owner_id', '!=', $viewerId))
            ->whereIn('owner_id', PublicProfile::visible()->select('user_id'))
            // Alleen routes met een omhullende; die overlapt het gevraagde gebied.
            ->whereNotNull('bbox_min_lat')
            ->where('bbox_min_lat', '<=', $maxLat)->where('bbox_max_lat', '>=', $minLat)
            ->where('bbox_min_lon', '<=', $maxLon)->where('bbox_max_lon', '>=', $minLon);

        if (! empty($data['sport'])) {
            $query->where('sport', $data['sport']);
        }

        $candidates = $query->latest('published_at')->when(!$all, fn ($q) => $q->limit(self::CANDIDATES))->get();

        // Makers van wie het profiel openbaar delen toestaat (Connect-beleid kan dat uitzetten).
        $profiles = PublicProfile::visible()
            ->whereIn('user_id', $candidates->pluck('owner_id')->unique()->all())
            ->with('user')
            ->get()
            ->filter(fn (PublicProfile $p) => $p->user && app(ConnectManagement::class)->allows($p->user, 'public_links'))
            ->keyBy('user_id');

        $routes = $candidates
            ->filter(fn (RouteMap $r) => $profiles->has((int) $r->owner_id))
            ->map(function (RouteMap $r) use ($centerLat, $centerLon) {
                $r->setAttribute('_distance_km', PublicRoutePresenter::haversine(
                    $centerLat, $centerLon,
                    ((float) $r->bbox_min_lat + (float) $r->bbox_max_lat) / 2,
                    ((float) $r->bbox_min_lon + (float) $r->bbox_max_lon) / 2,
                ) / 1000);

                return $r;
            })
            ->filter(fn (RouteMap $r) => \App\Support\RouteDiscoveryFilters::matches($r, $data))
            ->sortBy('_distance_km')
            ->when(!$all, fn ($r) => $r->take((int) ($data['limit'] ?? self::DEFAULT_LIMIT)))
            ->values();

        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);
        $trails = $this->trailCandidates($data, $minLat, $minLon, $maxLat, $maxLon, $limit);

        // Routes van gebruikers en OpenStreetMap-routes staan door elkaar, op afstand gesorteerd.
        // Met `all` krijgen alleen de dichtstbijzijnde een volledige samenvatting (zie FULL_ITEMS).
        $markers = [];
        $total = $routes->count() + $trails->count();
        if ($all) {
            $ranked = collect($routes->all())->concat($trails->all())->sortBy('_distance_km')->values();
            $full = $ranked->take(self::FULL_ITEMS);
            $routes = $full->filter(fn ($m) => $m instanceof RouteMap)->values();
            $trails = $full->filter(fn ($m) => $m instanceof TrailRoute)->values();
            $markers = $ranked->slice(self::FULL_ITEMS)->take(self::MAX_MARKERS)->map(fn ($m) => $this->marker($m))->values()->all();
        }

        $summaries = PublicRoutePresenter::routeSummaries($routes);
        $items = array_merge($this->userRoutes($summaries, $routes, $profiles), $this->trailItems($trails));
        usort($items, fn (array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);

        $response = [
            'bbox' => array_map(fn ($v) => round($v, 5), [$minLat, $minLon, $maxLat, $maxLon]),
            'routes' => $all ? $items : array_slice($items, 0, $limit),
        ];
        if ($all) {
            // `routes` is dan de dichtstbijzijnde selectie; de rest staat compact in `markers`
            // (`truncated`: niet alles is volledig). `total`: alle routes die in het gebied passen.
            $response += ['markers' => $markers, 'truncated' => $total > count($items), 'total' => $total];
        }

        return response()->json($response);
    }

    /**
     * Een route die buiten de volledige selectie valt: net genoeg voor een marker op de kaart en
     * om hem later op te vragen. Het startpunt komt uit goedkope velden (geen pad decoderen).
     *
     * @return array{id: string, slug: string, source: string, sport: ?string, lat: float, lon: float}
     */
    private function marker(RouteMap|TrailRoute $route): array
    {
        if ($route instanceof TrailRoute) {
            return [
                'id' => 'osm-' . $route->id,
                'slug' => $route->slug,
                'source' => 'osm',
                'sport' => TrailRoutePresenter::sport($route),
                'lat' => round((float) $route->start_lat, 5),
                'lon' => round((float) $route->start_lon, 5),
            ];
        }

        $first = $route->locations[0] ?? null;
        $hasStart = is_array($first) && isset($first['lat'], $first['lon']) && is_numeric($first['lat']) && is_numeric($first['lon']);

        return [
            'id' => $route->id,
            'slug' => PublicRoutePresenter::routeSlug($route),
            'source' => $route->source ?: 'milmap',
            'sport' => $route->sport,
            'lat' => round($hasStart ? (float) $first['lat'] : ((float) $route->bbox_min_lat + (float) $route->bbox_max_lat) / 2, 5),
            'lon' => round($hasStart ? (float) $first['lon'] : ((float) $route->bbox_min_lon + (float) $route->bbox_max_lon) / 2, 5),
        ];
    }

    /** De routes van gebruikers als lijstitems (samenvatting + afstand + maker). */
    private function userRoutes(array $summaries, $routes, $profiles): array
    {
        return array_map(function (array $summary, RouteMap $r) use ($profiles) {
            $profile = PublicProfileController::present($profiles->get((int) $r->owner_id));

            return $summary + [
                'distance_km' => round((float) $r->getAttribute('_distance_km'), 1),
                // Alleen wat nodig is om de maker te herkennen en zijn profiel te openen.
                'author' => [
                    'handle' => $profile['handle'],
                    'name' => $profile['name'],
                    'avatar_url' => $profile['avatar_url'],
                ],
            ];
        }, $summaries, $routes->all());
    }

    /**
     * Geïmporteerde OpenStreetMap-routes (trail_routes) in het gebied, met dezelfde
     * filters als de routes van gebruikers. OSM kent geen moeilijkheid of
     * hoogtemeters: filters daarop sluiten ze uit. Geeft alleen de kandidaten (zonder samenvatting).
     */
    private function trailCandidates(array $data, float $minLat, float $minLon, float $maxLat, float $maxLon, int $limit): \Illuminate\Support\Collection
    {
        $type = null;
        if (! empty($data['sport'])) {
            $type = TrailRoutePresenter::typeForSport($data['sport']);
            if ($type === null) {
                return collect();
            }
        }
        if (! empty($data['difficulty']) || isset($data['min_ascent']) || isset($data['max_ascent'])) {
            return collect();
        }

        $centerLat = ($minLat + $maxLat) / 2;
        $centerLon = ($minLon + $maxLon) / 2;

        // Zonder de zware `geometry`: de preview staat in een eigen kolom (zie TrailRoute).
        $candidates = TrailRoute::published()
            ->withoutGeometry()
            ->inBounds($minLat, $minLon, $maxLat, $maxLon)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when(isset($data['min_km']), fn ($q) => $q->where('distance_km', '>=', $data['min_km']))
            ->when(isset($data['max_km']), fn ($q) => $q->where('distance_km', '<=', $data['max_km']))
            ->when(! empty($data['route_type']), fn ($q) => $q->where('is_loop', $data['route_type'] === 'loop'))
            // Dichtstbijzijnde eerst, zodat de kandidatenlimiet geen nabije routes afknipt.
            ->orderByRaw(
                '(((min_lat + max_lat) / 2 - ?) * ((min_lat + max_lat) / 2 - ?)) + (((min_lon + max_lon) / 2 - ?) * ((min_lon + max_lon) / 2 - ?))',
                [$centerLat, $centerLat, $centerLon, $centerLon]
            )
            ->when(empty($data['all']), fn ($q) => $q->limit(self::CANDIDATES))
            ->get();

        $routes = $candidates
            ->map(function (TrailRoute $t) use ($centerLat, $centerLon) {
                $t->setAttribute('_distance_km', PublicRoutePresenter::haversine(
                    $centerLat, $centerLon,
                    ((float) $t->min_lat + (float) $t->max_lat) / 2,
                    ((float) $t->min_lon + (float) $t->max_lon) / 2,
                ) / 1000);

                return $t;
            })
            ->filter(function (TrailRoute $t) use ($data) {
                $minutes = ((float) $t->distance_km / TrailRoutePresenter::speedKmh($t)) * 60;

                return (! isset($data['min_minutes']) || $minutes >= $data['min_minutes'])
                    && (! isset($data['max_minutes']) || $minutes <= $data['max_minutes'])
                    && (! isset($data['radius_km']) || $t->getAttribute('_distance_km') <= $data['radius_km']);
            })
            ->sortBy('_distance_km')
            ->when(empty($data['all']), fn ($r) => $r->take($limit))
            ->values();

        return $routes;
    }

    /**
     * De OSM-routes als lijstitems (samenvatting + afstand).
     *
     * @param  \Illuminate\Support\Collection<int, TrailRoute>  $routes  zonder geometry geladen
     */
    private function trailItems(\Illuminate\Support\Collection $routes): array
    {
        TrailRoutePresenter::fillMissingPreviews($routes);

        return array_map(
            fn (array $summary, TrailRoute $t) => $summary + ['distance_km' => round((float) $t->getAttribute('_distance_km'), 1)],
            TrailRoutePresenter::summaries($routes),
            $routes->all()
        );
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} [minLat, minLon, maxLat, maxLon] */
    private function parseBbox(string $bbox, bool $all = false): array
    {
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', explode(',', $bbox));

        abort_if(
            $minLat < -90 || $maxLat > 90 || $minLon < -180 || $maxLon > 180 || $minLat >= $maxLat || $minLon >= $maxLon,
            422,
            'Ongeldig gebied.'
        );

        if ($all) return [$minLat, $minLon, $maxLat, $maxLon];

        // Te groot gebied: houd het midden en kort in.
        $latSpan = $maxLat - $minLat;
        if ($latSpan > self::MAX_SPAN_DEG) {
            $mid = ($minLat + $maxLat) / 2;
            $minLat = $mid - self::MAX_SPAN_DEG / 2;
            $maxLat = $mid + self::MAX_SPAN_DEG / 2;
        }
        $lonSpan = $maxLon - $minLon;
        if ($lonSpan > self::MAX_SPAN_DEG * 1.5) {
            $mid = ($minLon + $maxLon) / 2;
            $minLon = $mid - self::MAX_SPAN_DEG * 0.75;
            $maxLon = $mid + self::MAX_SPAN_DEG * 0.75;
        }

        return [$minLat, $minLon, $maxLat, $maxLon];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function bboxAround(float $lat, float $lon, float $radiusKm): array
    {
        $dLat = $radiusKm / 110.574;
        $dLon = $radiusKm / (111.320 * max(0.2, cos(deg2rad($lat))));

        return [
            max(-90.0, $lat - $dLat), max(-180.0, $lon - $dLon),
            min(90.0, $lat + $dLat), min(180.0, $lon + $dLon),
        ];
    }
}
