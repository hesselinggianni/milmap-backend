<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Mission;
use App\Models\RouteMap;
use App\Models\MapShare;
use App\Support\RouteGeometry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RouteMapController extends Controller
{
    /**
     * Assert the current user can access (view or edit) this map.
     * Throws 403 if the policy denies it; returns the loaded Map on success.
     */
    private function authorizeMap(string $mapId, bool $requireEdit = false): Map
    {
        $map = Map::findOrFail($mapId);
        $this->authorize($requireEdit ? 'update' : 'view', $map);
        return $map;
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        // Alle kaarten waar de gebruiker bij mag — zelfde regel als MapPolicy
        // (eigenaar, kaart-medewerker én deelnemer van een missie die deze
        // kaart van de missie-eigenaar koppelt). De oude eigen query miste
        // die missie-toegang: een genodigde zag de kaart wel, maar kreeg hier
        // stil een lege lijst routekaarten terug.
        $mapIds = app(\App\Services\MapAccess::class)->accessibleQuery(auth()->user())->pluck('id');

        $query = RouteMap::query()
            ->with(['owner', 'map'])
            ->whereIn('map_id', $mapIds);

        // optional filter by map_id
        if ($request->has('map_id')) {
            $query->where('map_id', $request->map_id);
        }

        return response()->json(
            $query->latest()->get()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(string $id)
    {
        $routeMap = RouteMap::with(['owner', 'map'])
            ->findOrFail($id);

        $this->authorize('view', $routeMap->map);

        return response()->json($this->presentRouteMap($routeMap));
    }

    /**
     * Velden waarmee een route later vindbaar en te beoordelen is: het gesnapte
     * pad ([lat, lon]-paren), de sport, het hoogteprofiel ([afstand_m, hoogte_m])
     * en stijgen/dalen. Allemaal optioneel; `source`/`attribution` zet alleen de server.
     */
    private function discoveryRules(): array
    {
        return [
            'path' => ['nullable', 'array', 'max:4000'],
            'path.*' => ['array', 'size:2'],
            'path.*.0' => ['numeric', 'between:-90,90'],
            'path.*.1' => ['numeric', 'between:-180,180'],

            'sport' => ['nullable', 'in:hike,bike,mtb,run'],

            'elevation_profile' => ['nullable', 'array', 'max:1000'],
            'elevation_profile.*' => ['array', 'size:2'],
            'elevation_profile.*.0' => ['numeric', 'min:0'],
            'elevation_profile.*.1' => ['numeric', 'between:-500,9000'],

            'ascent_m' => ['nullable', 'integer', 'between:0,30000'],
            'descent_m' => ['nullable', 'integer', 'between:0,30000'],
        ];
    }

    /**
     * Zet het ruwe pad en profiel uit het verzoek om naar opslagwaarden:
     * pad → vereenvoudigde encoded polyline, profiel → compact, en vul stijgen/dalen
     * (en `total_elevation` als dat nog leeg is) aan.
     */
    private function applyDiscoveryInput(array $validated): array
    {
        $hasPath = array_key_exists('path', $validated);
        $hasProfile = array_key_exists('elevation_profile', $validated);
        $path = $validated['path'] ?? null;
        $profile = $validated['elevation_profile'] ?? null;
        unset($validated['path'], $validated['elevation_profile']);

        if ($hasPath) {
            $validated['path_polyline'] = $path ? RouteGeometry::encode(RouteGeometry::simplify($path)) : null;
            if (! $path) {
                $validated['elevation_profile'] = null;
                $validated['ascent_m'] = null;
                $validated['descent_m'] = null;
            }
        }

        if ($hasProfile && $profile) {
            $full = RouteGeometry::compactProfile($profile, 1000);
            $validated['elevation_profile'] = RouteGeometry::compactProfile($profile) ?: null;

            if (count($full) >= 2) {
                [$up, $down] = RouteGeometry::ascentDescent($full);
                $validated['ascent_m'] ??= $up;
                $validated['descent_m'] ??= $down;
            }
        } elseif ($hasProfile) {
            $validated['elevation_profile'] = null;
        }

        // `total_elevation` is de bestaande (waypoint-)som en daardoor vaak 0: vul aan met het echte stijgen.
        if (($validated['ascent_m'] ?? null) !== null && (float) ($validated['total_elevation'] ?? 0) <= 0) {
            $validated['total_elevation'] = $validated['ascent_m'];
        }

        return $validated;
    }

    /** Shape a route map for the API response, including the linked mission summary. */
    private function presentRouteMap(RouteMap $routeMap): array
    {
        $data = $routeMap->toArray();

        $data['linked_mission'] = null;
        if ($routeMap->mission_id) {
            $mission = Mission::find($routeMap->mission_id);
            if ($mission && $mission->hasAccess(auth()->id())) {
                $data['linked_mission'] = [
                    'id'     => $mission->id,
                    'name'   => $mission->name,
                    'status' => $mission->status,
                    'date'   => $mission->date?->toDateString(),
                    'time'   => $mission->time,
                    'ogroup' => $mission->ogroupFor((int) auth()->id()),
                ];
            }
        }

        return $data;
    }

    public function getByMapId(Request $request, string $mapId)
    {
        $map = Map::findOrFail($mapId);

        // Share-token path: unauthenticated public read
        $shareToken = $request->query('share_token');
        if ($shareToken) {
            app(\App\Services\ConnectManagement::class)->assertPublicResource($map);
            $share = MapShare::active()
                ->where('token', $shareToken)
                ->where('map_id', $mapId)
                ->first();

            if (!$share) {
                abort(403, 'Invalid or expired share token');
            }
        } else {
            $this->authorize('view', $map);
        }

        // Via een deellink is de bezoeker anoniem: van de eigenaar alleen de
        // weergavevelden meesturen, geen e-mail/Stripe/instellingen.
        $owner = $shareToken ? 'owner:id,first_name,last_name,avatar_path' : 'owner';
        $routeMaps = RouteMap::with([$owner, 'map'])
            ->where('map_id', $mapId)
            ->get();

        return response()->json($routeMaps);
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $validated = $request->validate([
            'map_id' => [
                'required',
                'uuid',
                'exists:maps,id',
            ],

            'title' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'time' => ['nullable'],

            'color' => ['nullable', 'string'],

            'declination' => ['nullable', 'numeric', 'between:-180,180'],

            'equipment' => ['nullable', 'string'],

            'speed' => ['nullable', 'numeric'],

            'ic' => ['nullable', 'string'],
            'cs' => ['nullable', 'string'],

            'locations' => ['nullable', 'array'],

            'pause_time' => ['nullable', 'numeric'],
            'total_time' => ['nullable', 'numeric'],

            'total_distance' => ['nullable', 'numeric'],
            'total_elevation' => ['nullable', 'numeric'],

            'meta' => ['nullable', 'array'],

            'bijlage' => ['nullable', 'array'],
            'callsign' => ['nullable', 'string'],
            'eenheid' => ['nullable', 'string'],
            'sterkte' => ['nullable', 'integer'],
            'kaartblad' => ['nullable', 'string'],
            'vtv_vta' => ['nullable', 'string'],
        ] + $this->discoveryRules());

        $validated = $this->applyDiscoveryInput($validated);

        $this->authorizeMap($validated['map_id'], requireEdit: true);

        $routeMap = RouteMap::create([
            'id' => Str::uuid(),

            'owner_id' => auth()->id(),

            ...$validated,
        ]);

        return response()->json([
            'message' => 'RouteMap aangemaakt',
            'data' => $routeMap,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, string $id)
    {
        $routeMap = RouteMap::findOrFail($id);

        $this->authorize('update', $routeMap->map);

        $validated = $request->validate([
            'title' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'time' => ['nullable'],

            'color' => ['nullable', 'string'],

            'declination' => ['nullable', 'numeric', 'between:-180,180'],

            'equipment' => ['nullable', 'string'],

            'speed' => ['nullable', 'numeric'],

            'ic' => ['nullable', 'string'],
            'cs' => ['nullable', 'string'],

            'locations' => ['nullable', 'array'],

            'pause_time' => ['nullable', 'numeric'],
            'total_time' => ['nullable', 'numeric'],

            'total_distance' => ['nullable', 'numeric'],
            'total_elevation' => ['nullable', 'numeric'],

            'meta' => ['nullable', 'array'],
            'column_settings' => ['nullable', 'array'],
            'column_order' => ['nullable', 'array'],

            'bijlage' => ['nullable', 'array'],
            'callsign' => ['nullable', 'string'],
            'eenheid' => ['nullable', 'string'],
            'sterkte' => ['nullable', 'integer'],
            'kaartblad' => ['nullable', 'string'],
            'vtv_vta' => ['nullable', 'string'],

            'mission_id' => ['nullable', 'uuid', 'exists:missions,id'],
        ] + $this->discoveryRules());

        $validated = $this->applyDiscoveryInput($validated);

        $this->guardLiveAssignment($routeMap, $validated['locations'] ?? null);

        // Ensure numeric fields are never null (DB columns are NOT NULL)
        if (array_key_exists('pause_time', $validated)) {
            $validated['pause_time'] = $validated['pause_time'] ?? 0;
        }
        if (array_key_exists('total_time', $validated)) {
            $validated['total_time'] = $validated['total_time'] ?? 0;
        }
        if (array_key_exists('total_distance', $validated)) {
            $validated['total_distance'] = $validated['total_distance'] ?? 0;
        }
        if (array_key_exists('total_elevation', $validated)) {
            $validated['total_elevation'] = $validated['total_elevation'] ?? 0;
        }

        $routeMap->update($validated);
        $routeMap->refresh();

        return response()->json([
            'message' => 'RouteMap bijgewerkt',
            'data' => $this->presentRouteMap($routeMap),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GARMIN PUSH
    |--------------------------------------------------------------------------
    */

    /**
     * POST /api/v1/routemaps/{id}/push-garmin
     * Pusht deze routekaart als "course" naar het gekoppelde Garmin-account
     * (queued — zie App\Jobs\PushRouteMapToGarmin).
     */
    public function pushGarmin(string $id)
    {
        $routeMap = RouteMap::findOrFail($id);
        $this->authorize('update', $routeMap->map);

        if (! auth()->user()->garminAccount) {
            return response()->json(['message' => 'Garmin niet gekoppeld'], 422);
        }

        $routeMap->update(['garmin_push_status' => 'pending', 'garmin_push_error' => null]);

        \App\Jobs\PushRouteMapToGarmin::dispatch($routeMap->id, auth()->id());

        return response()->json(['status' => 'pending'], 202);
    }

    /*
    |--------------------------------------------------------------------------
    | LINK MISSION
    |--------------------------------------------------------------------------
    */

    /**
     * PATCH /api/v1/routemaps/{id}/mission
     * Body: { mission_id: uuid } or { mission_id: null } to unlink.
     */
    public function linkMission(Request $request, string $id)
    {
        $routeMap = RouteMap::findOrFail($id);

        $this->authorize('update', $routeMap->map);

        $validated = $request->validate([
            'mission_id' => ['nullable', 'uuid', 'exists:missions,id'],
        ]);

        // If linking, verify user has access to that mission
        if ($validated['mission_id']) {
            $mission = Mission::findOrFail($validated['mission_id']);
            if (!$mission->hasAccess(auth()->id())) {
                abort(403, 'No access to that mission');
            }
        }

        $routeMap->update(['mission_id' => $validated['mission_id']]);
        $routeMap->refresh();

        return response()->json(['data' => $this->presentRouteMap($routeMap)]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESTROY
    |--------------------------------------------------------------------------
    */

    public function destroy(string $id)
    {
        $routeMap = RouteMap::findOrFail($id);

        $this->authorize('update', $routeMap->map);

        $this->guardLiveAssignment($routeMap, deleting: true);

        $routeMap->delete();

        return response()->json([
            'message' => 'RouteMap verwijderd',
        ]);
    }

    /**
     * Routes van een KAKO-training in productie staan vast (zie
     * RouteAssignmentController::lockedChange): deelnemer-app, check-ins en score leunen op
     * deze benen. Zonder deze controle omzeilt een kaartbewerker de vergrendeling via de gewone
     * routekaart-API (locaties aanpassen of de route verwijderen), zonder logregel.
     */
    private function guardLiveAssignment(RouteMap $routeMap, ?array $newLocations = null, bool $deleting = false): void
    {
        if (! $routeMap->route_assignment_id) {
            return;
        }
        if (($routeMap->assignment?->settings['status'] ?? 'draft') !== 'live') {
            return;
        }
        if (! $deleting && ($newLocations === null || $newLocations == $routeMap->locations)) {
            return;
        }

        abort(423, 'Deze route hoort bij een training in productie: wijzigen of verwijderen kan niet. Zet de training eerst terug naar bewerken.');
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE UPLOAD
    |--------------------------------------------------------------------------
    */

    /**
     * Afbeeldingen van een checkpoint als platte array, met backward-compat
     * voor de oude losse `flyoverImage`.
     */
    private function checkpointImages(array $loc): array
    {
        if (!empty($loc['images']) && is_array($loc['images'])) {
            return array_values(array_filter($loc['images']));
        }
        if (!empty($loc['flyoverImage'])) {
            return [$loc['flyoverImage']];
        }
        return [];
    }

    /**
     * Upload an image for a checkpoint (max 3 per punt)
     * POST /api/v1/routemaps/{id}/checkpoints/{checkpointId}/upload-image
     */
    public function uploadCheckpointImage(string $id, string $checkpointId)
    {
        $request = request();

        // Validate file is present
        if (!$request->hasFile('image')) {
            return response()->json([
                'message' => 'Geen afbeelding geupload',
            ], 400);
        }

        $routeMap = RouteMap::findOrFail($id);

        $this->authorize('update', $routeMap->map);

        try {
            // Get locations array
            $locations = $routeMap->locations ?? [];

            // Find the checkpoint location
            $checkpointIndex = null;
            foreach ($locations as $index => $loc) {
                if ((string) ($loc['id'] ?? '') === (string) $checkpointId) {
                    $checkpointIndex = $index;
                    break;
                }
            }

            if ($checkpointIndex === null) {
                return response()->json([
                    'message' => 'Checkpoint niet gevonden',
                ], 404);
            }

            // Bestaande afbeeldingen (max 3 per punt). Backward-compat: een oude
            // losse flyoverImage telt als eerste afbeelding.
            $images = $this->checkpointImages($locations[$checkpointIndex]);
            if (count($images) >= 3) {
                return response()->json([
                    'message' => 'Maximaal 3 afbeeldingen per punt',
                ], 422);
            }

            // Upload de nieuwe afbeelding en voeg toe aan de lijst.
            $uploadService = new \App\Services\ImageUploadService();
            $uploadResult = $uploadService->store(
                $request->file('image'),
                $id,
                $checkpointId
            );

            $images[] = $uploadResult['url'];
            $locations[$checkpointIndex]['images'] = array_values($images);
            // flyoverImage = eerste afbeelding (backward-compat).
            $locations[$checkpointIndex]['flyoverImage'] = $images[0] ?? null;

            // Save route map
            $routeMap->update(['locations' => $locations]);

            return response()->json([
                'url' => $uploadResult['url'],
                'images' => $locations[$checkpointIndex]['images'],
                'location' => $locations[$checkpointIndex],
            ]);
        } catch (\Exception $e) {
            $statusCode = (int) $e->getCode() ?: 422;
            return response()->json([
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    /**
     * Delete an image for a checkpoint
     * DELETE /api/v1/routemaps/{id}/checkpoints/{checkpointId}/image
     */
    public function deleteCheckpointImage(string $id, string $checkpointId)
    {
        $routeMap = RouteMap::findOrFail($id);

        $this->authorize('update', $routeMap->map);

        try {
            $locations = $routeMap->locations ?? [];

            // Find the checkpoint location
            $checkpointIndex = null;
            foreach ($locations as $index => $loc) {
                if ((string) ($loc['id'] ?? '') === (string) $checkpointId) {
                    $checkpointIndex = $index;
                    break;
                }
            }

            if ($checkpointIndex === null) {
                return response()->json([
                    'message' => 'Checkpoint niet gevonden',
                ], 404);
            }

            $images = $this->checkpointImages($locations[$checkpointIndex]);
            $targetUrl = request('url');
            $uploadService = new \App\Services\ImageUploadService();

            if ($targetUrl) {
                // Verwijder één specifieke afbeelding.
                $images = array_values(array_filter($images, fn ($u) => $u !== $targetUrl));
                $filename = $uploadService->getFilenameFromUrl($targetUrl);
                if ($filename) {
                    $uploadService->delete($id, $filename);
                }
            } else {
                // Geen url meegegeven → verwijder alle afbeeldingen van dit punt.
                foreach ($images as $u) {
                    $filename = $uploadService->getFilenameFromUrl($u);
                    if ($filename) {
                        $uploadService->delete($id, $filename);
                    }
                }
                $images = [];
            }

            $locations[$checkpointIndex]['images'] = $images;
            $locations[$checkpointIndex]['flyoverImage'] = $images[0] ?? null;

            // Save route map
            $routeMap->update(['locations' => $locations]);

            return response()->json([
                'message' => 'Afbeelding verwijderd',
                'images' => $images,
                'location' => $locations[$checkpointIndex],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
