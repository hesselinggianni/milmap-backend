<?php

namespace App\Http\Controllers;

use App\Models\GeneratedRoute;
use App\Models\RouteMap;
use App\Models\Map;
use App\Services\RouteGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RouteGenerationController extends Controller
{
    protected $routeGenerationService;

    public function __construct(RouteGenerationService $routeGenerationService)
    {
        $this->routeGenerationService = $routeGenerationService;
    }

    /**
     * Generate a route preview between checkpoints
     * POST /api/v1/route-maps/{routeMapId}/generate-route
     */
    public function generateRoute(Request $request, string $routeMapId)
    {
        $routeMap = RouteMap::findOrFail($routeMapId);

        // Check if user has access to this route map
        if (! $this->canEdit($routeMap)) {
            abort(403, 'Unauthorized to generate routes for this map');
        }

        // Validate input
        $validated = $request->validate([
            'terrain'        => 'required|in:footpaths,roads,cross-country',
            'optimization'   => 'required|array|min:1',
            'optimization.*' => 'in:distance,elevation,time',
        ]);

        try {
            // Extract checkpoints from route map locations
            $checkpoints = $this->extractCheckpoints($routeMap);

            if (count($checkpoints) < 2) {
                return response()->json([
                    'message' => 'At least 2 checkpoints required for route generation',
                ], 422);
            }

            // Generate route using service
            $generatedRoute = $this->routeGenerationService->generateRoute(
                $checkpoints,
                $validated
            );

            // Enforce one generated route per route map — remove any previous one
            GeneratedRoute::forRouteMap($routeMapId)->delete();

            // Save generated route to database
            $savedRoute = GeneratedRoute::create([
                'id' => Str::uuid(),
                'route_map_id' => $routeMapId,
                'user_id' => auth()->id(),
                'options' => $validated,
                'route_geojson' => $generatedRoute['route_geojson'],
                'total_distance_m' => $generatedRoute['total_distance_m'],
                'total_elevation_m' => $generatedRoute['total_elevation_m'],
                'total_time_minutes' => $generatedRoute['total_time_minutes'],
                'status' => 'generated',
            ]);

            return response()->json([
                'id' => $savedRoute->id,
                'route_geojson' => $savedRoute->route_geojson,
                'total_distance_m' => $savedRoute->total_distance_m,
                'total_elevation_m' => $savedRoute->total_elevation_m,
                'total_time_minutes' => $savedRoute->total_time_minutes,
                'waypoints' => $generatedRoute['waypoints'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List generated routes for a route map
     * GET /api/v1/route-maps/{routeMapId}/generated-routes
     */
    public function listGeneratedRoutes(string $routeMapId)
    {
        $routeMap = RouteMap::findOrFail($routeMapId);

        // Check if user has access
        if (! $this->canView($routeMap)) {
            abort(403, 'Unauthorized');
        }

        $routes = GeneratedRoute::forRouteMap($routeMapId)
            ->latest()
            ->get()
            ->map(fn ($route) => [
                'id' => $route->id,
                'status' => $route->status,
                'total_distance_m' => $route->total_distance_m,
                'total_elevation_m' => $route->total_elevation_m,
                'total_time_minutes' => $route->total_time_minutes,
                'options' => $route->options,
                'created_at' => $route->created_at,
                'applied_at' => $route->applied_at,
            ]);

        return response()->json($routes);
    }

    /**
     * Get a specific generated route
     * GET /api/v1/generated-routes/{id}
     */
    public function getGeneratedRoute(string $id)
    {
        $generatedRoute = GeneratedRoute::findOrFail($id);

        // Check access
        if (! $this->canViewRoute($generatedRoute)) {
            abort(403, 'Unauthorized');
        }

        return response()->json([
            'id' => $generatedRoute->id,
            'route_geojson' => $generatedRoute->route_geojson,
            'total_distance_m' => $generatedRoute->total_distance_m,
            'total_elevation_m' => $generatedRoute->total_elevation_m,
            'total_time_minutes' => $generatedRoute->total_time_minutes,
            'options' => $generatedRoute->options,
            'status' => $generatedRoute->status,
            'created_at' => $generatedRoute->created_at,
            'applied_at' => $generatedRoute->applied_at,
        ]);
    }

    /**
     * Apply a generated route (convert waypoints to locations in route map)
     * POST /api/v1/generated-routes/{id}/apply
     */
    public function applyRoute(Request $request, string $id)
    {
        $generatedRoute = GeneratedRoute::findOrFail($id);

        // Check if user can edit the route map
        if (! $this->canEdit($generatedRoute->routeMap)) {
            abort(403, 'Unauthorized to apply route');
        }

        try {
            $routeMap = $generatedRoute->routeMap;

            // Get current locations
            $locations = $routeMap->locations ?? [];

            // Find checkpoint indices
            $checkpointIndices = [];
            foreach ($locations as $index => $location) {
                if (isset($location['checkpoint']) && $location['checkpoint']['enabled'] === true) {
                    $checkpointIndices[] = $index;
                }
            }

            if (count($checkpointIndices) < 2) {
                return response()->json([
                    'message' => 'Unable to determine checkpoints for route application',
                ], 422);
            }

            // Insert waypoints between checkpoints
            $coordinates     = $generatedRoute->route_geojson['coordinates'];
            $terrainCrossings = $this->routeGenerationService->findTerrainCrossings($coordinates);
            $newLocations    = $this->insertRouteWaypoints($locations, $coordinates, $checkpointIndices, $generatedRoute->options ?? [], $terrainCrossings);

            // Update route map with new locations
            $routeMap->update([
                'locations' => $newLocations,
            ]);

            // Mark generated route as applied
            $generatedRoute->apply();

            // Broadcast update to collaborators (optional - requires WebSocket setup)
            // broadcast(new RouteApplied($routeMap))->toOthers();

            return response()->json([
                'message' => 'Route applied successfully',
                'locations' => $newLocations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to apply route: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete a generated route
     * DELETE /api/v1/generated-routes/{id}
     */
    public function deleteGeneratedRoute(string $id)
    {
        $generatedRoute = GeneratedRoute::findOrFail($id);

        // Check if user can edit the route map
        if (! $this->canEdit($generatedRoute->routeMap)) {
            abort(403, 'Unauthorized');
        }

        // Can only delete if not yet applied
        if ($generatedRoute->status === 'applied') {
            return response()->json([
                'message' => 'Cannot delete an applied route',
            ], 422);
        }

        $generatedRoute->delete();

        return response()->json([
            'message' => 'Generated route deleted',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Extract checkpoint coordinates from a route map
     */
    private function extractCheckpoints(RouteMap $routeMap): array
    {
        $locations = $routeMap->locations ?? [];
        $checkpoints = [];

        foreach ($locations as $location) {
            if (isset($location['checkpoint']) && $location['checkpoint']['enabled'] === true) {
                $checkpoints[] = [
                    'lat' => (float) $location['lat'],
                    'lon' => (float) $location['lon'],
                ];
            }
        }

        return $checkpoints;
    }

    /**
     * Insert waypoints between checkpoints.
     *
     * Two kinds of waypoints are created:
     *  1. Turn / direction-change points — via Douglas-Peucker (index-based).
     *     Straight sections produce zero intermediates; corners keep exactly one.
     *  2. Terrain-feature crossing points — water, power lines, roads, bridges —
     *     always inserted regardless of DP, labelled with the feature type.
     *
     * DP epsilon (perpendicular-deviation tolerance in °):
     *   0.00009° ≈  10 m  (elevation mode — keeps fine terrain detail)
     *   0.00015° ≈  17 m  (default    — drops gentle curves, keeps real turns)
     */
    private function insertRouteWaypoints(
        array $locations,
        array $routeCoordinates,
        array $checkpointIndices,
        array $options = [],
        array $terrainCrossings = []
    ): array {
        $optList = (array) ($options['optimization'] ?? ['distance']);
        $epsilon = in_array('elevation', $optList) ? 0.00009 : 0.00015;

        $result      = [];
        $coordIndex  = 0;
        $totalCoords = count($routeCoordinates);

        for ($i = 0; $i < count($locations); $i++) {
            $result[] = $locations[$i];

            if (in_array($i, array_slice($checkpointIndices, 0, -1))) {
                $nextCheckpointIndex = $checkpointIndices[array_search($i, $checkpointIndices) + 1] ?? null;

                if ($nextCheckpointIndex !== null) {
                    $segmentStartIdx = $coordIndex;
                    $segmentCoords   = [];

                    while ($coordIndex < $totalCoords - 1) {
                        $coordIndex++;
                        $coord = $routeCoordinates[$coordIndex];

                        if ($this->isCloseToLocation($coord, $locations[$nextCheckpointIndex])) {
                            break;
                        }

                        $segmentCoords[] = $coord;
                    }

                    // ── 1. Douglas-Peucker (returns kept *indices* into $segmentCoords) ──
                    $dpIndices = empty($segmentCoords)
                        ? []
                        : $this->douglasPeuckerIndices($segmentCoords, $epsilon);

                    // Build merged waypoint list: [{origIdx, coord, label}, ...]
                    $waypoints = [];

                    foreach ($dpIndices as $idx) {
                        $waypoints[] = [
                            'origIdx' => $segmentStartIdx + $idx + 1, // absolute index in routeCoordinates
                            'coord'   => $segmentCoords[$idx],
                            'label'   => '',
                        ];
                    }

                    // ── 2. Terrain-feature crossings for this segment ──────────────────
                    foreach ($terrainCrossings as $crossing) {
                        $ci = $crossing['routeSegmentIndex'];
                        if ($ci > $segmentStartIdx && $ci <= $coordIndex) {
                            $waypoints[] = [
                                'origIdx' => $ci,
                                'coord'   => [$crossing['lon'], $crossing['lat']],
                                'label'   => $crossing['label'],
                            ];
                        }
                    }

                    // Sort by position along route, deduplicate very close ones
                    usort($waypoints, fn ($a, $b) => $a['origIdx'] <=> $b['origIdx']);
                    $waypoints = $this->deduplicateWaypoints($waypoints);

                    foreach ($waypoints as $wp) {
                        $result[] = [
                            'id'         => (string) Str::uuid(),
                            'lat'        => (float) $wp['coord'][1],
                            'lon'        => (float) $wp['coord'][0],
                            'checkpoint' => ['enabled' => false],
                            'label'      => $wp['label'],
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Douglas-Peucker — returns the *indices* of kept points (never moves points).
     * Straight sections collapse to just [0, last]; corners keep the apex index.
     *
     * @param  array  $coords  [[lon, lat], ...]
     * @param  float  $epsilon perpendicular tolerance in degrees
     * @param  int    $start   start index (recursive use)
     * @param  int    $end     end index   (recursive use, -1 = last)
     * @return int[]  Sorted unique indices of surviving coords
     */
    private function douglasPeuckerIndices(array $coords, float $epsilon, int $start = 0, int $end = -1): array
    {
        if ($end === -1) $end = count($coords) - 1;
        if ($end <= $start + 1) return [$start, $end];

        $maxDist  = 0.0;
        $maxIndex = $start;

        for ($i = $start + 1; $i < $end; $i++) {
            $dist = $this->perpendicularDistance($coords[$i], $coords[$start], $coords[$end]);
            if ($dist > $maxDist) {
                $maxDist  = $dist;
                $maxIndex = $i;
            }
        }

        if ($maxDist > $epsilon) {
            $left  = $this->douglasPeuckerIndices($coords, $epsilon, $start, $maxIndex);
            $right = $this->douglasPeuckerIndices($coords, $epsilon, $maxIndex, $end);
            return array_unique(array_merge($left, $right));
        }

        return [$start, $end];
    }

    /**
     * Perpendicular distance (degrees) from point P to line segment A→B.
     */
    private function perpendicularDistance(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];

        if ($dx == 0.0 && $dy == 0.0) {
            return sqrt(($p[0] - $a[0]) ** 2 + ($p[1] - $a[1]) ** 2);
        }

        $t     = (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / ($dx ** 2 + $dy ** 2);
        $t     = max(0.0, min(1.0, $t));
        $nearX = $a[0] + $t * $dx;
        $nearY = $a[1] + $t * $dy;

        return sqrt(($p[0] - $nearX) ** 2 + ($p[1] - $nearY) ** 2);
    }

    /**
     * Remove duplicate / very-close waypoints (< 25 m apart).
     * Terrain-feature crossings (label ≠ '') take priority over plain turn points.
     */
    private function deduplicateWaypoints(array $waypoints, float $minMeters = 25.0): array
    {
        $result = [];
        $last   = null;

        foreach ($waypoints as $wp) {
            if ($last === null) {
                $result[] = $wp;
                $last = $wp;
                continue;
            }

            $dlat = ($wp['coord'][1] - $last['coord'][1]) * 111320;
            $dlon = ($wp['coord'][0] - $last['coord'][0]) * 111320 * cos(deg2rad($wp['coord'][1]));
            $dist = sqrt($dlat ** 2 + $dlon ** 2);

            if ($dist < $minMeters) {
                // Keep the one with a label (terrain feature) if there is one
                if ($wp['label'] !== '' && end($result)['label'] === '') {
                    array_pop($result);
                    $result[] = $wp;
                    $last = $wp;
                }
                // Otherwise discard the duplicate
                continue;
            }

            $result[] = $wp;
            $last = $wp;
        }

        return $result;
    }

    /**
     * Check if a coordinate is close to a location
     */
    private function isCloseToLocation(array $coord, array $location, float $threshold = 0.001): bool
    {
        $latDiff = abs($coord[1] - ($location['lat'] ?? $location['latitude'] ?? 0));
        $lonDiff = abs($coord[0] - ($location['lon'] ?? $location['longitude'] ?? 0));

        return $latDiff < $threshold && $lonDiff < $threshold;
    }

    /**
     * Check if user can view route map
     */
    private function canView(RouteMap $routeMap): bool
    {
        $map = $routeMap->map;

        return ((int) $map->owner_id) === auth()->id() ||
               $map->collaborators()
                   ->where('user_id', auth()->id())
                   ->where('status', 'accepted')
                   ->exists();
    }

    /**
     * Check if user can edit route map
     */
    private function canEdit(RouteMap $routeMap): bool
    {
        return $this->canView($routeMap);
    }

    /**
     * Check if user can view generated route
     */
    private function canViewRoute(GeneratedRoute $generatedRoute): bool
    {
        return $this->canView($generatedRoute->routeMap);
    }
}
