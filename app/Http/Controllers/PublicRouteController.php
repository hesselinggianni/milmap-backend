<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Map;
use App\Models\PublicProfile;
use App\Models\RouteMap;
use App\Models\TrailRoute;
use App\Models\User;
use App\Support\PublicRoutePresenter;
use App\Support\RouteGeometry;
use App\Support\TrailRoutePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ImageUploadService;

/**
 * Routes publiceren op je openbare profiel, ze bekijken, en ze "gebruiken"
 * (klonen naar je eigen kaart).
 *
 * Een gekloonde route is een gewone, eigen RouteMap: de bron kan hem daarna
 * niet meer wijzigen of intrekken, en het origineel blijft ongemoeid.
 */
class PublicRouteController extends Controller
{
    // ── Publiceren (eigenaar) ────────────────────────────────────────────

    /** PUT /api/v1/routemaps/{id}/visibility */
    public function setRouteVisibility(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['is_public' => ['required', 'boolean']]);

        $route = RouteMap::findOrFail($id);
        abort_unless((int) $route->owner_id === (int) Auth::id(), 403, 'Alleen de maker kan deze route publiceren.');
        $this->authorize('update', $route->map);
        abort_if($route->connect_organization_id, 403, 'Organisatieroutes kunnen niet openbaar worden gezet.');

        $profile = $this->publishableProfile($data['is_public']);

        $route->forceFill([
            'is_public' => $data['is_public'],
            'published_at' => $data['is_public'] ? now() : null,
        ])->save();

        return response()->json($this->visibilityPayload($route->is_public, $route->published_at, $profile));
    }

    /** PUT /api/v1/activities/{id}/visibility */
    public function setActivityVisibility(Request $request, $id): JsonResponse
    {
        $data = $request->validate(['is_public' => ['required', 'boolean']]);

        $activity = Activity::where('user_id', Auth::id())->findOrFail($id);
        if ($activity->map_id) {
            abort_if(optional(Map::find($activity->map_id))->connect_organization_id, 403, 'Organisatie-tracks kunnen niet openbaar worden gezet.');
        }

        $profile = $this->publishableProfile($data['is_public']);

        $activity->forceFill([
            'is_public' => $data['is_public'],
            'published_at' => $data['is_public'] ? now() : null,
        ])->save();

        return response()->json($this->visibilityPayload($activity->is_public, $activity->published_at, $profile));
    }

    /** POST /api/v1/routemaps/{id}/photos */
    public function uploadRoutePhoto(Request $request, string $id): JsonResponse
    {
        $route = RouteMap::findOrFail($id);
        abort_unless((int) $route->owner_id === (int) Auth::id(), 403);
        $this->authorize('update', $route->map);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        abort_if($route->photos()->count() >= 6, 422, 'Maximaal 6 foto\'s per route.');

        $stored = app(ImageUploadService::class)->storeForRouteMap($request->file('image'), $route->id);
        $photo = $route->photos()->create($stored);

        return response()->json(['photo' => $photo], 201);
    }

    /** DELETE /api/v1/routemaps/{id}/photos/{photoId} */
    public function deleteRoutePhoto(string $id, int $photoId): JsonResponse
    {
        $route = RouteMap::findOrFail($id);
        abort_unless((int) $route->owner_id === (int) Auth::id(), 403);
        $this->authorize('update', $route->map);
        $photo = $route->photos()->findOrFail($photoId);
        app(ImageUploadService::class)->deleteForRouteMap($route->id, $photo->filename);
        $photo->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Bekijken (publiek) ───────────────────────────────────────────────

    /** GET /api/v1/profiles/{handle}/routes/{id} */
    public function showRoute(string $handle, string $id): JsonResponse
    {
        $profile = PublicProfileController::visibleProfile($handle);
        $route = PublicProfileController::findPublicRoute($profile->user_id, $id);

        return response()->json([
            'profile' => PublicProfileController::present($profile),
            'route' => PublicRoutePresenter::routeDetail($route),
        ]);
    }

    /** GET /api/v1/profiles/{handle}/tracks/{id} */
    public function showTrack(string $handle, $id): JsonResponse
    {
        $profile = PublicProfileController::visibleProfile($handle);
        $activity = PublicProfileController::publicActivitiesQuery($profile->user_id)->findOrFail($id);
        $detail = PublicRoutePresenter::trackDetail($activity, $profile);
        abort_if($detail === null, 404);

        return response()->json([
            'profile' => PublicProfileController::present($profile),
            'track' => $detail,
        ]);
    }

    // ── Gebruiken / klonen (ingelogd) ────────────────────────────────────

    /**
     * Neem een openbare routekaart over: komt als eigen routekaart in een
     * bestaande kaart (`map_id`) of in een nieuwe kaart.
     * POST /api/v1/profiles/{handle}/routes/{id}/clone
     */
    public function cloneRoute(Request $request, string $handle, string $id): JsonResponse
    {
        $data = $request->validate([
            'map_id' => ['nullable', 'uuid', 'exists:maps,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = PublicProfileController::visibleProfile($handle);
        $source = PublicProfileController::publicRoutesQuery($profile->user_id)->findOrFail($id);

        $stamp = (int) (microtime(true) * 1000);
        $locations = [];
        foreach (PublicRoutePresenter::cleanLocations($source->locations ?? []) as $i => $loc) {
            $locations[] = ['id' => $stamp + $i] + $loc;
        }

        return $this->cloneInto($request, $profile, 'route_map', (string) $source->id, $data['map_id'] ?? null, [
            'title' => $data['title'] ?? $source->title ?? 'Route',
            'color' => $source->color,
            'speed' => $source->speed ?? 5,
            'declination' => $source->declination,
            'locations' => $locations,
            'total_distance' => (float) $source->total_distance,
            'total_elevation' => (float) $source->total_elevation,
            'total_time' => (int) $source->total_time,
            // Een overgenomen route is een gewone route (geen routekaart voor print/militair gebruik):
            // zo past hij bij de keuze "gewone route / routekaart" in de routeplanner.
            'meta' => ['kind' => 'route'],

            // Het gesnapte pad, de sport en het hoogteprofiel gaan mee, samen met
            // de bron: een route van OpenStreetMap blijft als zodanig herkenbaar.
            'sport' => $source->sport,
            'difficulty' => $source->difficulty,
            'path_polyline' => $source->path_polyline,
            'elevation_profile' => $source->elevation_profile,
            'ascent_m' => $source->ascent_m,
            'descent_m' => $source->descent_m,
            'source' => $source->source ?: 'milmap',
            'source_ref' => $source->source_ref,
            'attribution' => $source->attribution,
        ]);
    }

    /**
     * Een geïmporteerde OpenStreetMap-route (Routes ontdekken) opslaan als eigen
     * route: een gewone RouteMap met het echte pad, de sport en de OSM-bronvermelding.
     * POST /api/v1/trail-routes/{slug}/clone
     */
    public function cloneTrailRoute(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'map_id' => ['nullable', 'uuid', 'exists:maps,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $source = TrailRoute::published()->where('slug', $slug)->firstOrFail();
        $line = array_merge(...(TrailRoutePresenter::segments($source) ?: [[]]));
        abort_if(count($line) < 2, 404);

        $stamp = (int) (microtime(true) * 1000);
        $locations = [];
        foreach (PublicRoutePresenter::cleanLocations(TrailRoutePresenter::waypoints($source)) as $i => $loc) {
            $locations[] = ['id' => $stamp + $i] + $loc;
        }

        return $this->cloneInto($request, null, TrailRoutePresenter::CLONE_TYPE, (string) $source->id, $data['map_id'] ?? null, [
            'title' => $data['title'] ?? $source->name,
            'color' => '#2b7fff',
            'speed' => TrailRoutePresenter::speedKmh($source),
            'locations' => $locations,
            'total_distance' => round((float) $source->distance_km * 1000, 1),
            'total_elevation' => 0,
            'total_time' => 0,
            'meta' => ['kind' => 'route'],

            // Het OSM-pad blijft meegaan (met `source`/`attribution`, ODbL): de omhullende
            // en de vingerafdruk zet RouteMap::syncDiscoveryFields.
            'sport' => TrailRoutePresenter::sport($source),
            'path_polyline' => RouteGeometry::encode(RouteGeometry::simplify($line)),
            'source' => 'osm',
            'source_ref' => (string) $source->id,
            'attribution' => $source->attribution,
        ]);
    }

    /**
     * Gebruik een openbare track als route. De track zelf is geen route: de
     * app vereenvoudigt hem (op basis van de openbare, ingekorte punten) tot
     * waypoints en stuurt die mee als `locations`.
     * POST /api/v1/profiles/{handle}/tracks/{id}/clone
     */
    public function cloneTrack(Request $request, string $handle, $id): JsonResponse
    {
        $data = $request->validate([
            'map_id' => ['nullable', 'uuid', 'exists:maps,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'locations' => ['required', 'array', 'min:2', 'max:60'],
            'locations.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.lon' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.mgrs' => ['nullable', 'string', 'max:24'],
            'locations.*.elevation' => ['nullable', 'numeric'],
            'locations.*.title' => ['nullable', 'string', 'max:120'],
        ]);

        $profile = PublicProfileController::visibleProfile($handle);
        $source = PublicProfileController::publicActivitiesQuery($profile->user_id)->findOrFail($id);
        abort_if(count(PublicRoutePresenter::publicPoints($source, $profile)) < 2, 404);

        $stamp = (int) (microtime(true) * 1000);
        $locations = PublicRoutePresenter::cleanLocations($data['locations']);
        foreach ($locations as $i => &$loc) {
            $loc = ['id' => $stamp + $i] + $loc;
        }
        unset($loc);

        // Afstand uit de ontvangen punten zelf berekend (meter, zoals de app), niet uit de client.
        $distance = 0.0;
        for ($i = 1; $i < count($locations); $i++) {
            $distance += PublicRoutePresenter::haversine(
                $locations[$i - 1]['lat'], $locations[$i - 1]['lon'],
                $locations[$i]['lat'], $locations[$i]['lon']
            );
        }

        $speed = $source->avg_speed_kmh ? round((float) $source->avg_speed_kmh, 1) : match ($source->type) {
            'ride' => 18, 'run' => 10, 'drive' => 50, default => 5,
        };

        return $this->cloneInto($request, $profile, 'activity', (string) $source->id, $data['map_id'] ?? null, [
            'title' => $data['title'] ?? $source->title ?? 'Route',
            'color' => '#2b7fff',
            'speed' => $speed,
            'locations' => $locations,
            'total_distance' => round($distance, 1),
            'total_elevation' => (float) $source->elevation_gain_m,
            'total_time' => 0,
            'meta' => ['kind' => 'route'],
        ]);
    }

    // ── Intern ───────────────────────────────────────────────────────────

    /** `$profile` is null voor een route zonder maker (een geïmporteerde OpenStreetMap-route). */
    private function cloneInto(Request $request, ?PublicProfile $profile, string $type, string $sourceId, ?string $mapId, array $attrs): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $map = null;

        if ($mapId) {
            $map = Map::findOrFail($mapId);
            $this->authorize('update', $map);
        } elseif ($limit = $this->freeMapLimitResponse($user)) {
            return $limit;
        }

        [$map, $route] = DB::transaction(function () use ($user, $map, $attrs, $type, $sourceId, $profile) {
            $map ??= Map::create([
                'title' => $attrs['title'],
                'settings' => [
                    'locationformat' => 'mgrs',
                    'maxZoom' => 100,
                    'mapExtent' => null,
                    'language' => $user->language ?: 'nl',
                    'useCurrentCoordsAsBounds' => false,
                ],
                'status' => 'active',
                'owner_id' => $user->id,
            ]);

            $route = RouteMap::create($attrs + [
                'id' => (string) Str::uuid(),
                'map_id' => $map->id,
                'owner_id' => $user->id,
                'pause_time' => 0,
                'cloned_from_type' => $type,
                'cloned_from_id' => $sourceId,
                'cloned_from_user_id' => $profile?->user_id,
            ]);

            return [$map, $route];
        });

        return response()->json([
            'map' => ['id' => $map->id, 'title' => $map->title],
            'routemap' => ['id' => $route->id, 'title' => $route->title],
            'source' => $profile ? PublicProfileController::present($profile) : null,
        ], 201);
    }

    /** Zelfde gratis-limiet als MapController::store: een nieuwe kaart kan een upgrade vragen. */
    private function freeMapLimitResponse(User $user): ?JsonResponse
    {
        if (($user->is_admin ?? false) || $user->hasPremiumAccess()) {
            return null;
        }
        if (Map::where('owner_id', $user->id)->count() < User::FREE_MAP_LIMIT) {
            return null;
        }

        return response()->json([
            'message' => 'Je gratis account kan maximaal ' . User::FREE_MAP_LIMIT
                . ' kaarten opslaan. Neem een abonnement voor onbeperkte kaarten.',
            'code' => 'subscription_required',
            'feature' => 'unlimited_maps',
        ], 402);
    }

    /** Publiceren kan alleen met een openbaar profiel; intrekken altijd. */
    private function publishableProfile(bool $publishing): ?PublicProfile
    {
        $profile = PublicProfile::where('user_id', Auth::id())->first();

        if ($publishing && (! $profile || ! $profile->enabled)) {
            abort(response()->json([
                'message' => 'Zet eerst je openbare profiel aan om routes te kunnen publiceren.',
                'code' => 'public_profile_required',
            ], 422));
        }

        return $profile;
    }

    private function visibilityPayload(bool $isPublic, $publishedAt, ?PublicProfile $profile): array
    {
        return [
            'is_public' => $isPublic,
            'published_at' => $publishedAt?->toIso8601String(),
            'profile_handle' => $profile?->handle,
        ];
    }
}
