<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapShare;
use App\Models\MapWaypoint;
use App\Models\Report;
use App\Models\RouteMap;
use App\Models\UserLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapShareController extends Controller
{

    /**
     * Welke kaartdata een deellink mag tonen. De eigenaar kiest dit per link in
     * de deel-dialoog; alles staat standaard aan zodat bestaande links (zonder
     * `content`-blok) precies blijven tonen wat ze altijd toonden.
     */
    private const CONTENT_DEFAULTS = [
        'routes'           => true,  // routelijnen + route-waypoints op de kaart
        'route_table'      => true,  // tabel met alle punten
        'route_attachment' => true,  // bijlage (belangrijke coördinaten, notities)
        'waypoints'        => true,  // losse waypoints
        'waypoint_notes'   => true,  // notitietekst bij een waypoint
        'waypoint_photos'  => true,  // foto's bij een waypoint
        'areas'            => true,  // gemarkeerde gebieden / meldingen
        'area_details'     => true,  // SALUTE-velden + beschrijving van een gebied
    ];

    /**
     * Normaliseer een door de client aangeleverd content-blok naar booleans.
     * Onbekende sleutels vallen af; ontbrekende sleutels vallen terug op true.
     */
    private function normalizeContent($content): array
    {
        $content = is_array($content) ? $content : [];
        $out = [];
        foreach (self::CONTENT_DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $content)
                ? filter_var($content[$key], FILTER_VALIDATE_BOOLEAN)
                : $default;
        }

        // Sub-opties zijn betekenisloos zonder hun hoofdlaag.
        if (! $out['waypoints']) {
            $out['waypoint_notes'] = false;
            $out['waypoint_photos'] = false;
        }
        if (! $out['areas']) {
            $out['area_details'] = false;
        }
        if (! $out['routes']) {
            $out['route_table'] = false;
            $out['route_attachment'] = false;
        }

        return $out;
    }

    /**
     * Het content-blok van een bestaande share, aangevuld met de defaults.
     */
    private function contentOf(MapShare $share): array
    {
        return $this->normalizeContent($share->settings['content'] ?? []);
    }

    /**
     * List shares for a map
     */
    public function index($mapId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $shares = MapShare::where('map_id', $map->id)
            ->latest()
            ->get();

        return response()->json($shares);
    }

    /**
     * Create a share link
     */
    public function store(Request $request, $mapId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $request->validate([
            'route_map_ids' => 'nullable|array',
            'settings' => 'nullable|array',
            'title' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
            'share_live_location' => 'nullable|boolean',
            'content' => 'nullable|array',
        ]);

        $settings = $request->settings ?? [
            'showTable' => true,
            'showMarkers' => true,
            'showLines' => true,
        ];
        if ($request->has('share_live_location')) {
            $settings['shareLiveLocation'] = (bool) $request->boolean('share_live_location');
        }
        $settings['content'] = $this->normalizeContent($request->input('content'));

        $share = MapShare::create([
            'map_id' => $map->id,
            'created_by' => Auth::id(),
            'route_map_ids' => $request->route_map_ids,
            'settings' => $settings,
            'title' => $request->title ?? $map->title,
            'expires_at' => $request->expires_at,
        ]);

        return response()->json($share, 201);
    }

    /**
     * Update a share link (o.a. live-locatie aan/uit per link).
     */
    public function update(Request $request, $mapId, $shareId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $share = MapShare::where('id', $shareId)
            ->where('map_id', $map->id)
            ->firstOrFail();

        $data = $request->validate([
            'title'                => 'sometimes|nullable|string|max:255',
            'expires_at'           => 'sometimes|nullable|date',
            'settings'             => 'sometimes|nullable|array',
            'share_live_location'  => 'sometimes|boolean',
            'content'              => 'sometimes|array',
        ]);

        if (array_key_exists('title', $data)) {
            $share->title = $data['title'];
        }
        if (array_key_exists('expires_at', $data)) {
            $share->expires_at = $data['expires_at'];
        }

        // share_live_location leeft als losse vlag in het settings-json — geen
        // aparte kolom nodig, share-instellingen zijn al een JSON-blob.
        $settings = array_merge($share->settings ?? [], $data['settings'] ?? []);
        if (array_key_exists('share_live_location', $data)) {
            $settings['shareLiveLocation'] = $data['share_live_location'];
        }
        if (array_key_exists('content', $data)) {
            $settings['content'] = $this->normalizeContent($data['content']);
        }
        $share->settings = $settings;

        $share->save();

        return response()->json($share);
    }

    /**
     * Delete a share link
     */
    public function destroy($mapId, $shareId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $share = MapShare::where('id', $shareId)
            ->where('map_id', $map->id)
            ->firstOrFail();

        $share->delete();

        return response()->json(null, 204);
    }

    /**
     * Public: get shared map data by token (no auth required)
     */
    public function showByToken($token)
    {
        // Niet filteren op expiry hier: de frontend (ShareView.vue) haalt de
        // share altijd op en toont zelf een nette "verlopen"-melding aan de
        // hand van expires_at. Filterden we hier al met active(), dan kreeg
        // een verlopen link nooit die melding — alleen een kale 404.
        $share = MapShare::where('token', $token)->firstOrFail();

        $map = $share->map;

        // Load route maps (filtered by share config)
        $routeMapsQuery = RouteMap::where('map_id', $map->id);

        if (!empty($share->route_map_ids)) {
            $routeMapsQuery->whereIn('id', $share->route_map_ids);
        }

        $content = $this->contentOf($share);

        $routeMaps = $content['routes'] ? $routeMapsQuery->get() : collect();

        // Bijlage-blok alleen meesturen als die laag gedeeld mag worden: wat de
        // ontvanger niet mag zien, hoort niet in de response te staan (verbergen
        // in de UI is geen bescherming).
        if (! $content['route_attachment']) {
            $routeMaps->each(function (RouteMap $routeMap) {
                $routeMap->bijlage = null;
            });
        }

        // Losse waypoints van de kaart, met de sub-data die is uitgevinkt eruit
        // gestript.
        $waypoints = [];
        if ($content['waypoints']) {
            $waypoints = MapWaypoint::where('map_id', $map->id)
                ->when($content['waypoint_photos'], fn ($q) => $q->with('images'))
                ->get()
                ->map(function (MapWaypoint $waypoint) use ($content) {
                    $data = $waypoint->toClientArray();
                    if (! $content['waypoint_notes']) {
                        $data['note'] = null;
                    }
                    if (! $content['waypoint_photos']) {
                        $data['images'] = [];
                    }
                    return $data;
                })
                ->values()
                ->all();
        }

        // Gemarkeerde gebieden (meldingen). Zonder 'area_details' blijft alleen
        // de geometrie + het type over, zodat het gebied wel op de kaart staat
        // maar de inhoudelijke SALUTE-velden privé blijven.
        $reports = [];
        if ($content['areas']) {
            $reports = Report::where('map_id', $map->id)
                ->get()
                ->map(function (Report $report) use ($content) {
                    $base = [
                        'id'         => $report->id,
                        'category'   => $report->category,
                        'type'       => $report->type,
                        'subtype'    => $report->subtype,
                        'latitude'   => (float) $report->latitude,
                        'longitude'  => (float) $report->longitude,
                        'status'     => $report->status,
                        'created_at' => $report->created_at?->toIso8601String(),
                        // De tekenlaag zit in metadata; alleen de geometrie is
                        // nodig om het gebied te kunnen tonen.
                        'metadata'   => array_filter(
                            [
                                'saluteGeometry' => $report->metadata['saluteGeometry'] ?? null,
                                'salutePolygon'  => $report->metadata['salutePolygon'] ?? null,
                                'approachRoute'  => $report->metadata['approachRoute'] ?? null,
                                'title'          => $report->metadata['title'] ?? null,
                                'reportColor'    => $report->metadata['reportColor'] ?? null,
                                'reportSymbol'   => $report->metadata['reportSymbol'] ?? null,
                            ],
                            fn ($value) => $value !== null
                        ),
                    ];

                    if (! $content['area_details']) {
                        return $base;
                    }

                    return $base + [
                        'urgency'          => $report->urgency,
                        'timing'           => $report->timing,
                        'size'             => $report->size,
                        'count'            => $report->count,
                        'activity'         => $report->activity,
                        'equipment'        => $report->equipment,
                        'risk'             => $report->risk,
                        'hazardType'       => $report->hazardType,
                        'avalancheLevel'   => $report->avalancheLevel,
                        'roadCondition'    => $report->roadCondition,
                        'weatherCondition' => $report->weatherCondition,
                        'description'      => $report->description,
                        'metadata'         => $report->metadata,
                    ];
                })
                ->values()
                ->all();
        }

        // Live-locatie van de deler: alleen als de share-eigenaar dit voor déze
        // link heeft aangezet én z'n locatie op deze kaart nog vers is (binnen
        // 2 min. geüpdatet — dezelfde freshness-regel als UserLocationController).
        $liveLocation = null;
        if (($share->settings['shareLiveLocation'] ?? false) === true) {
            $loc = UserLocation::where('map_id', $map->id)
                ->where('user_id', $share->created_by)
                ->where('last_updated_at', '>', now()->subMinutes(2))
                ->with('user:id,first_name,last_name,avatar_path')
                ->first();

            if ($loc && $loc->user) {
                // De route mag alleen zichtbaar zijn wanneer hij ook deel is van
                // deze share-link. Zo kan een uitgesloten routemap niet alsnog
                // via de live-locatie van de deler worden afgeleid.
                $activeRoute = $loc->route_map_id
                    ? $routeMaps->first(
                        fn (RouteMap $routeMap) => (string) $routeMap->id === (string) $loc->route_map_id
                    )
                    : null;
                $displayName = trim(sprintf(
                    '%s %s',
                    $loc->user->first_name ?? '',
                    $loc->user->last_name ?? ''
                ));

                $liveLocation = [
                    'user_id'         => $loc->user_id,
                    // Een openbare share-link toont nooit het e-mailadres als
                    // profielnaam wanneer een account nog geen naam heeft.
                    'user_name'       => $displayName !== '' ? $displayName : 'MilMap-gebruiker',
                    'avatar_url'      => $loc->user->avatar_url,
                    'latitude'        => (float) $loc->latitude,
                    'longitude'       => (float) $loc->longitude,
                    'accuracy'        => $loc->accuracy !== null ? (float) $loc->accuracy : null,
                    'heading'         => $loc->heading !== null ? (float) $loc->heading : null,
                    'speed'           => $loc->speed !== null ? (float) $loc->speed : null,
                    'is_navigating'   => $activeRoute !== null,
                    'route_map_id'    => $activeRoute?->id,
                    'route_map_title' => $activeRoute?->title,
                    'last_updated_at' => $loc->last_updated_at->toIso8601String(),
                ];
            }
        }

        return response()->json([
            'share' => [
                'id' => $share->id,
                'title' => $share->title,
                'token' => $share->token,
                'settings' => $share->settings,
                'expires_at' => $share->expires_at,
                'share_live_location' => ($share->settings['shareLiveLocation'] ?? false) === true,
                'content' => $content,
            ],
            'map' => $map,
            'routeMaps' => $routeMaps,
            'waypoints' => $waypoints,
            'reports' => $reports,
            'live_location' => $liveLocation,
        ]);
    }
}
