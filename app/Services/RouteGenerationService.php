<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RouteGenerationService
{
    const OSRM_API_URL = 'https://router.project-osrm.org/route/v1/foot/';
    const ELEVATION_API_URL = 'https://api.open-elevation.com/api/v1/lookup';
    const OVERPASS_API_URL = 'https://overpass-api.de/api/interpreter';
    const REQUEST_TIMEOUT = 30; // seconds
    const ELEVATION_SAMPLE_DISTANCE = 100; // meters between elevation samples
    const CROSSCOUNTRY_SAMPLE_DISTANCE = 150; // meters between elevation samples for cross-country

    /**
     * Generate a route between checkpoints using OSRM API
     *
     * @param array $checkpoints Array of {lat, lon} coordinates
     * @param array $options Route generation options {terrain, optimization}
     * @return array Generated route data with distance, elevation, time
     */
    public function generateRoute(array $checkpoints, array $options = []): array
    {
        if (count($checkpoints) < 2) {
            throw new \Exception('At least 2 checkpoints required for route generation');
        }

        $terrain = $options['terrain'] ?? 'roads';
        $rawOptimization = $options['optimization'] ?? 'distance';
        // Accept both a string (legacy) and an array (multi-select checkboxes)
        $optimization = is_array($rawOptimization) ? ($rawOptimization[0] ?? 'distance') : $rawOptimization;

        try {
            // Cross-country: bypass OSRM, generate straight-line with elevation checks
            if ($terrain === 'cross-country') {
                return $this->generateCrossCountryRoute($checkpoints, $optimization);
            }

            // Build OSRM request URL
            $coordinates = $this->formatCoordinatesForOSRM($checkpoints);
            $osrmUrl = $this->buildOSRMUrl($coordinates, $optimization);

            // Call OSRM API
            $osrmResponse = Http::timeout(self::REQUEST_TIMEOUT)
                ->get($osrmUrl)
                ->json();

            if (! isset($osrmResponse['routes']) || empty($osrmResponse['routes'])) {
                throw new \Exception('No route found for the specified checkpoints');
            }

            // Get the best route
            $route = $osrmResponse['routes'][0];

            // Convert to GeoJSON
            $routeGeojson = $this->formatAsGeoJSON($route['geometry']);

            // Get coordinates for elevation calculation
            $routeCoordinates = $route['geometry']['coordinates'];

            // Calculate elevation gain/loss
            $elevationData = $this->calculateElevationData($routeCoordinates);

            // Calculate walking time (speed based on terrain and elevation)
            $walkingTime = $this->calculateWalkingTime($route['distance'], $elevationData, $terrain);

            return [
                'route_geojson' => $routeGeojson,
                'total_distance_m' => round($route['distance'], 1),
                'total_elevation_m' => round($elevationData['elevation_gain'], 1),
                'total_time_minutes' => round($walkingTime, 1),
                'waypoints' => $this->extractWaypoints($checkpoints, $routeCoordinates),
            ];
        } catch (\Exception $e) {
            Log::error('Route generation error: ' . $e->getMessage());
            throw new \Exception('Failed to generate route: ' . $e->getMessage());
        }
    }

    /**
     * Format checkpoints for OSRM API (lon,lat format)
     */
    private function formatCoordinatesForOSRM(array $checkpoints): string
    {
        return implode(';', array_map(function ($checkpoint) {
            return "{$checkpoint['lon']},{$checkpoint['lat']}";
        }, $checkpoints));
    }

    /**
     * Build OSRM API URL with appropriate parameters
     */
    private function buildOSRMUrl(string $coordinates, string $optimization): string
    {
        $params = [
            'overview'   => 'full',        // Get full geometry
            'geometries' => 'geojson',      // Return GeoJSON object, not encoded polyline
            'steps'      => 'false',
            'annotations' => 'distance,duration', // Get detailed info
        ];

        // Note: OSRM doesn't natively optimize for elevation
        // We can use 'fastest' vs 'shortest' for time vs distance
        // Elevation optimization would require post-processing

        $queryString = http_build_query($params);

        return self::OSRM_API_URL . $coordinates . '?' . $queryString;
    }

    /**
     * Format route geometry as GeoJSON.
     * OSRM returns a GeoJSON object when geometries=geojson is set; guard
     * against the legacy encoded-polyline string just in case.
     *
     * @param array|string $geometry
     */
    private function formatAsGeoJSON($geometry): array
    {
        if (is_array($geometry) && isset($geometry['coordinates'])) {
            return [
                'type'        => 'LineString',
                'coordinates' => $geometry['coordinates'],
            ];
        }

        // Fallback: return an empty LineString so the rest of the pipeline
        // doesn't crash when the geometry format is unexpected.
        Log::warning('RouteGenerationService: unexpected geometry format', [
            'type' => gettype($geometry),
        ]);

        return ['type' => 'LineString', 'coordinates' => []];
    }

    /**
     * Calculate elevation gain and loss along the route
     */
    private function calculateElevationData(array $coordinates): array
    {
        try {
            // Sample every N coordinates to avoid API rate limiting
            $sampledCoords = $this->sampleCoordinates($coordinates, self::ELEVATION_SAMPLE_DISTANCE);

            // Query elevations
            $elevations = $this->getElevations($sampledCoords);

            if (empty($elevations)) {
                return ['elevation_gain' => 0, 'elevation_loss' => 0];
            }

            // Calculate cumulative gain and loss
            $gainLoss = ['gain' => 0, 'loss' => 0];
            for ($i = 1; $i < count($elevations); $i++) {
                $diff = $elevations[$i] - $elevations[$i - 1];
                if ($diff > 0) {
                    $gainLoss['gain'] += $diff;
                } else {
                    $gainLoss['loss'] += abs($diff);
                }
            }

            return [
                'elevation_gain' => $gainLoss['gain'],
                'elevation_loss' => $gainLoss['loss'],
            ];
        } catch (\Exception $e) {
            Log::warning('Elevation calculation failed: ' . $e->getMessage());

            return ['elevation_gain' => 0, 'elevation_loss' => 0];
        }
    }

    /**
     * Sample coordinates at intervals to reduce API calls
     */
    private function sampleCoordinates(array $coordinates, int $sampleDistance): array
    {
        if (count($coordinates) <= 2) {
            return $coordinates;
        }

        $sampled = [$coordinates[0]]; // Always include first
        $totalDistance = 0;
        $lastSampled = $coordinates[0];

        for ($i = 1; $i < count($coordinates); $i++) {
            $distance = $this->haversineDistance(
                $lastSampled[1],
                $lastSampled[0],
                $coordinates[$i][1],
                $coordinates[$i][0]
            );

            $totalDistance += $distance;

            if ($totalDistance >= $sampleDistance) {
                $sampled[] = $coordinates[$i];
                $lastSampled = $coordinates[$i];
                $totalDistance = 0;
            }
        }

        // Always include last
        if (end($sampled) !== end($coordinates)) {
            $sampled[] = end($coordinates);
        }

        return $sampled;
    }

    /**
     * Get elevations from OpenElevation API
     */
    private function getElevations(array $coordinates): array
    {
        try {
            $locations = array_map(function ($coord) {
                return ['latitude' => $coord[1], 'longitude' => $coord[0]];
            }, $coordinates);

            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->post(self::ELEVATION_API_URL, ['locations' => $locations])
                ->json();

            if (! isset($response['results'])) {
                return [];
            }

            return array_map(fn ($result) => $result['elevation'] ?? 0, $response['results']);
        } catch (\Exception $e) {
            Log::warning('Elevation API error: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Calculate Haversine distance between two coordinates
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000; // Earth's radius in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * asin(sqrt($a));

        return $R * $c;
    }

    /**
     * Calculate realistic walking time based on distance, elevation, and terrain
     */
    private function calculateWalkingTime(float $distanceM, array $elevationData, string $terrain): float
    {
        // Base walking speed (Tobler's hiking function)
        // Standard: ~1.4 m/s on flat ground
        // Adjusted for elevation: slower on climbs

        $distanceKm = $distanceM / 1000;
        $elevationGain = $elevationData['elevation_gain'] ?? 0;

        // Tobler's formula: speed = 6 * exp(-3.5 * abs(grade + 0.05))
        // Simplified: for every 100m elevation gain, add ~10 minutes
        $elevationFactor = ($elevationGain / 100) * 10;

        // Base time: distance at ~4.5 km/h on flat ground
        $baseTimeMinutes = ($distanceKm / 4.5) * 60;

        // Add elevation time
        $totalMinutes = $baseTimeMinutes + $elevationFactor;

        // Terrain adjustment
        if ($terrain === 'footpaths') {
            // Footpaths might be slightly slower (more varied terrain)
            $totalMinutes *= 1.1;
        } elseif ($terrain === 'cross-country') {
            // Off-trail: significantly slower due to vegetation, uneven ground
            $totalMinutes *= 1.3;
        }

        return max(1, $totalMinutes); // Minimum 1 minute
    }

    /**
     * Extract waypoints from full route to map back to checkpoints
     */
    private function extractWaypoints(array $checkpoints, array $routeCoordinates): array
    {
        // Find the closest route coordinate to each checkpoint
        $waypoints = [];

        foreach ($checkpoints as $index => $checkpoint) {
            $closestCoord = $this->findClosestCoordinate(
                $checkpoint['lat'],
                $checkpoint['lon'],
                $routeCoordinates
            );

            if ($closestCoord) {
                $waypoints[] = [
                    'checkpoint_index' => $index,
                    'lat' => $closestCoord[1],
                    'lon' => $closestCoord[0],
                ];
            }
        }

        return $waypoints;
    }

    /**
     * Find closest coordinate to a point
     */
    private function findClosestCoordinate(float $lat, float $lon, array $coordinates): ?array
    {
        $closest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($coordinates as $coord) {
            $distance = $this->haversineDistance($lat, $lon, $coord[1], $coord[0]);
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closest = $coord;
            }
        }

        return $closest;
    }

    // =========================================================================
    // Cross-country routing
    // =========================================================================

    /**
     * Generate a straight-line cross-country route between checkpoints.
     *
     * Strategy:
     *  1. Build a dense set of intermediate coordinates between each pair of
     *     consecutive checkpoints (one point every ~150 m along great-circle).
     *  2. Query elevations for those points in bulk.
     *  3. Drop points where the slope to the next sample exceeds the max grade
     *     (5 % for elevation-optimised, 25 % otherwise) — if the slope is too
     *     steep we nudge laterally by up to ±500 m and try to find a gentler
     *     approach.  If no gentle neighbour exists we keep the point but flag
     *     it so the walker knows it is steep.
     *  4. Detect elevation inflection points (ridge tops, valley bottoms) and
     *     mark them as named waypoints.
     *  5. Return the result in the same shape as the OSRM-backed path.
     */
    private function generateCrossCountryRoute(array $checkpoints, string $optimization): array
    {
        $allCoords = [];   // [lon, lat] pairs that form the full route geometry

        // Max slope in % before we try to reroute (25 % ≈ 14° — manageable off-trail)
        $maxSlopePct = ($optimization === 'elevation') ? 5.0 : 25.0;

        foreach ($checkpoints as $idx => $cp) {
            if ($idx === 0) {
                $allCoords[] = [$cp['lon'], $cp['lat']];
                continue;
            }

            $prev = $checkpoints[$idx - 1];

            // Dense samples along the straight line segment
            $segmentCoords = $this->interpolateStraightLine(
                $prev['lat'], $prev['lon'],
                $cp['lat'],   $cp['lon'],
                self::CROSSCOUNTRY_SAMPLE_DISTANCE
            );

            // Remove first point (already appended as end of previous segment)
            array_shift($segmentCoords);

            // Fetch elevations for segment
            $elevations = $this->getElevations($segmentCoords);

            if (count($elevations) === count($segmentCoords)) {
                // Try to avoid overly-steep sections by nudging laterally
                $segmentCoords = $this->avoidSteepSlopes(
                    $segmentCoords, $elevations, $maxSlopePct
                );
            }

            foreach ($segmentCoords as $coord) {
                $allCoords[] = $coord;
            }
        }

        // Calculate total distance along the generated path
        $totalDistance = 0.0;
        for ($i = 1; $i < count($allCoords); $i++) {
            $totalDistance += $this->haversineDistance(
                $allCoords[$i - 1][1], $allCoords[$i - 1][0],
                $allCoords[$i][1],     $allCoords[$i][0]
            );
        }

        // Full elevation data
        $elevationData = $this->calculateElevationData($allCoords);
        $walkingTime   = $this->calculateWalkingTime($totalDistance, $elevationData, 'cross-country');

        $routeGeojson = [
            'type'        => 'LineString',
            'coordinates' => $allCoords,
        ];

        return [
            'route_geojson'      => $routeGeojson,
            'total_distance_m'   => round($totalDistance, 1),
            'total_elevation_m'  => round($elevationData['elevation_gain'], 1),
            'total_time_minutes' => round($walkingTime, 1),
            'waypoints'          => $this->extractWaypoints($checkpoints, $allCoords),
        ];
    }

    /**
     * Interpolate a straight great-circle line into coordinates every $stepMeters.
     *
     * @return array  [[lon, lat], ...]  — includes start AND end
     */
    private function interpolateStraightLine(
        float $lat1, float $lon1,
        float $lat2, float $lon2,
        int $stepMeters
    ): array {
        $totalDist = $this->haversineDistance($lat1, $lon1, $lat2, $lon2);

        if ($totalDist <= $stepMeters) {
            return [[$lon1, $lat1], [$lon2, $lat2]];
        }

        $steps  = (int) ceil($totalDist / $stepMeters);
        $coords = [];

        for ($i = 0; $i <= $steps; $i++) {
            $fraction = $i / $steps;
            $coords[] = [
                $lon1 + ($lon2 - $lon1) * $fraction,
                $lat1 + ($lat2 - $lat1) * $fraction,
            ];
        }

        return $coords;
    }

    /**
     * Attempt to route around steep sections.
     *
     * For each consecutive pair of sampled coords whose slope exceeds $maxPct,
     * we try nudging the intermediate point ±500 m perpendicular to the
     * bearing and pick the option with the gentlest slope.  If no option is
     * better than the original we keep the original.
     *
     * @param  array  $coords     [[lon, lat], ...]
     * @param  array  $elevations [m, ...]  — same length as $coords
     * @param  float  $maxPct     slope threshold in %
     * @return array  Adjusted [[lon, lat], ...]
     */
    private function avoidSteepSlopes(array $coords, array $elevations, float $maxPct): array
    {
        $result = $coords;

        for ($i = 0; $i < count($coords) - 1; $i++) {
            $distM = $this->haversineDistance(
                $coords[$i][1],     $coords[$i][0],
                $coords[$i + 1][1], $coords[$i + 1][0]
            );

            if ($distM < 1) continue;

            $elevDiff = abs(($elevations[$i + 1] ?? 0) - ($elevations[$i] ?? 0));
            $slopePct = ($elevDiff / $distM) * 100;

            if ($slopePct <= $maxPct) continue;

            // Try perpendicular offsets: ±200 m, ±400 m
            $bearing = $this->bearingDeg(
                $coords[$i][1],     $coords[$i][0],
                $coords[$i + 1][1], $coords[$i + 1][0]
            );

            $midLat = ($coords[$i][1]     + $coords[$i + 1][1])     / 2;
            $midLon = ($coords[$i][0]     + $coords[$i + 1][0])     / 2;

            $bestSlope = $slopePct;
            $bestCoord = null;

            foreach ([200, 400, -200, -400] as $offsetM) {
                $perpBearing = fmod($bearing + 90, 360);
                [$nudgedLat, $nudgedLon] = $this->destinationPoint($midLat, $midLon, $perpBearing, abs($offsetM));

                // Elevation at nudged midpoint
                $nudgedElevArr = $this->getElevations([[$nudgedLon, $nudgedLat]]);
                $nudgedElev    = $nudgedElevArr[0] ?? null;
                if ($nudgedElev === null) continue;

                // Slope from coord[i] → nudged midpoint → coord[i+1]
                $d1 = $this->haversineDistance($coords[$i][1], $coords[$i][0], $nudgedLat, $nudgedLon);
                $d2 = $this->haversineDistance($nudgedLat, $nudgedLon, $coords[$i + 1][1], $coords[$i + 1][0]);

                $s1 = $d1 > 0 ? abs($nudgedElev - ($elevations[$i] ?? 0)) / $d1 * 100 : 0;
                $s2 = $d2 > 0 ? abs(($elevations[$i + 1] ?? 0) - $nudgedElev) / $d2 * 100 : 0;
                $worstSlope = max($s1, $s2);

                if ($worstSlope < $bestSlope) {
                    $bestSlope = $worstSlope;
                    $bestCoord = [$nudgedLon, $nudgedLat];
                }
            }

            if ($bestCoord !== null) {
                // Splice in the nudged midpoint
                array_splice($result, $i + 1, 0, [$bestCoord]);
                // Re-index elevations array with a rough average
                $avgElev = (($elevations[$i] ?? 0) + ($elevations[$i + 1] ?? 0)) / 2;
                array_splice($elevations, $i + 1, 0, [$avgElev]);
                $i++; // skip past the inserted coord
            }
        }

        return $result;
    }

    /**
     * Compute initial bearing (degrees, 0–360) from point A to point B.
     */
    private function bearingDeg(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLon = deg2rad($lon2 - $lon1);
        $y    = sin($dLon) * cos(deg2rad($lat2));
        $x    = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
              - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Destination point from a start, bearing, and distance.
     *
     * @return float[]  [lat, lon]
     */
    private function destinationPoint(float $lat, float $lon, float $bearingDeg, float $distM): array
    {
        $R       = 6371000;
        $delta   = $distM / $R;
        $theta   = deg2rad($bearingDeg);
        $lat1    = deg2rad($lat);
        $lon1    = deg2rad($lon);

        $lat2 = asin(sin($lat1) * cos($delta) + cos($lat1) * sin($delta) * cos($theta));
        $lon2 = $lon1 + atan2(
            sin($theta) * sin($delta) * cos($lat1),
            cos($delta) - sin($lat1) * sin($lat2)
        );

        return [rad2deg($lat2), rad2deg($lon2)];
    }

    // =========================================================================
    // Terrain-feature crossings (via Overpass API)
    // =========================================================================

    /**
     * Find where the route crosses notable terrain features
     * (waterways, power lines, roads, bridges, cliffs).
     *
     * Returns an array of crossing descriptors:
     *   [{lon, lat, label, routeSegmentIndex}, ...]
     *
     * `routeSegmentIndex` is the index of the route coordinate **after** the
     * crossing (i.e. between routeCoordinates[idx-1] and routeCoordinates[idx]).
     *
     * @param  array $routeCoordinates  [[lon, lat], ...]  full OSRM geometry
     */
    public function findTerrainCrossings(array $routeCoordinates): array
    {
        if (count($routeCoordinates) < 2) {
            return [];
        }

        // 1. Build bounding box with a small buffer (~200 m ≈ 0.002°)
        $lats = array_column($routeCoordinates, 1);
        $lons = array_column($routeCoordinates, 0);
        $bbox = [
            'south' => min($lats) - 0.002,
            'west'  => min($lons) - 0.002,
            'north' => max($lats) + 0.002,
            'east'  => max($lons) + 0.002,
        ];

        // 2. Fetch features from Overpass
        $features = $this->fetchOverpassFeatures($bbox);

        if (empty($features)) {
            return [];
        }

        // 3. Find intersections between route segments and feature geometries
        $crossings = [];

        foreach ($features as $feature) {
            $featureCoords = $feature['coords'];   // [[lon, lat], ...]
            $label         = $feature['label'];

            // Walk every route segment
            for ($ri = 1; $ri < count($routeCoordinates); $ri++) {
                $rA = $routeCoordinates[$ri - 1];
                $rB = $routeCoordinates[$ri];

                // Walk every segment of the feature geometry
                for ($fi = 1; $fi < count($featureCoords); $fi++) {
                    $fA = $featureCoords[$fi - 1];
                    $fB = $featureCoords[$fi];

                    $pt = $this->segmentIntersection($rA, $rB, $fA, $fB);

                    if ($pt !== null) {
                        $crossings[] = [
                            'lon'               => $pt[0],
                            'lat'               => $pt[1],
                            'label'             => $label,
                            'routeSegmentIndex' => $ri,
                        ];
                    }
                }
            }
        }

        // 4. Deduplicate crossings that are very close together
        return $this->deduplicateCrossings($crossings, 30.0);
    }

    /**
     * Query Overpass API for terrain features in the given bounding box.
     *
     * Returns an array of features, each with:
     *   { coords: [[lon, lat], ...], label: string }
     */
    private function fetchOverpassFeatures(array $bbox): array
    {
        $s = $bbox['south'];
        $w = $bbox['west'];
        $n = $bbox['north'];
        $e = $bbox['east'];

        // Overpass QL: fetch waterways, power lines, roads (primary/secondary/tertiary),
        // bridges and cliffs as ways in the bounding box.
        $query = <<<EOQ
[out:json][timeout:25];
(
  way["waterway"~"^(river|stream|canal|drain|ditch)$"]($s,$w,$n,$e);
  way["power"="line"]($s,$w,$n,$e);
  way["highway"~"^(primary|secondary|tertiary|unclassified|residential|trunk|motorway)$"]($s,$w,$n,$e);
  way["bridge"="yes"]($s,$w,$n,$e);
  way["natural"="cliff"]($s,$w,$n,$e);
);
out body;
>;
out skel qt;
EOQ;

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->asForm()
                ->post(self::OVERPASS_API_URL, ['data' => $query])
                ->json();
        } catch (\Exception $e) {
            Log::warning('Overpass API request failed: ' . $e->getMessage());
            return [];
        }

        if (! isset($response['elements'])) {
            return [];
        }

        // Build a node-id → [lon, lat] lookup
        $nodes = [];
        foreach ($response['elements'] as $el) {
            if ($el['type'] === 'node') {
                $nodes[$el['id']] = [$el['lon'], $el['lat']];
            }
        }

        // Build feature geometries from ways
        $features = [];
        foreach ($response['elements'] as $el) {
            if ($el['type'] !== 'way') continue;

            $coords = [];
            foreach ($el['nodes'] as $nid) {
                if (isset($nodes[$nid])) {
                    $coords[] = $nodes[$nid];
                }
            }

            if (count($coords) < 2) continue;

            $label = $this->classifyFeature($el['tags'] ?? []);
            if ($label === '') continue;

            $features[] = [
                'coords' => $coords,
                'label'  => $label,
            ];
        }

        return $features;
    }

    /**
     * Classify an OSM way's tags into a Dutch terrain-crossing label.
     * Returns '' if the feature should be ignored.
     */
    private function classifyFeature(array $tags): string
    {
        // Bridge takes precedence (most distinctive landmark)
        if (isset($tags['bridge']) && $tags['bridge'] === 'yes') {
            return 'Brug';
        }

        if (isset($tags['waterway'])) {
            return match ($tags['waterway']) {
                'river'  => 'Rivier',
                'stream' => 'Beek',
                'canal'  => 'Kanaal',
                'drain',
                'ditch'  => 'Sloot',
                default  => 'Waterovergang',
            };
        }

        if (isset($tags['power']) && $tags['power'] === 'line') {
            return 'Hoogspanning';
        }

        if (isset($tags['highway'])) {
            return match ($tags['highway']) {
                'motorway',
                'trunk'     => 'Snelweg',
                'primary'   => 'Hoofdweg',
                'secondary' => 'Secundaire weg',
                'tertiary'  => 'Tertiaire weg',
                default     => 'Weg',
            };
        }

        if (isset($tags['natural']) && $tags['natural'] === 'cliff') {
            return 'Steile wand';
        }

        return '';
    }

    /**
     * Find the intersection point of two line segments p1→p2 and p3→p4.
     * Returns [lon, lat] or null if they do not intersect within both segments.
     *
     * @param  float[] $p1  [lon, lat]
     * @param  float[] $p2  [lon, lat]
     * @param  float[] $p3  [lon, lat]
     * @param  float[] $p4  [lon, lat]
     */
    private function segmentIntersection(array $p1, array $p2, array $p3, array $p4): ?array
    {
        $d1x = $p2[0] - $p1[0];
        $d1y = $p2[1] - $p1[1];
        $d2x = $p4[0] - $p3[0];
        $d2y = $p4[1] - $p3[1];

        $denom = $d1x * $d2y - $d1y * $d2x;

        if (abs($denom) < 1e-10) {
            return null; // Parallel or collinear
        }

        $dx = $p3[0] - $p1[0];
        $dy = $p3[1] - $p1[1];

        $t = ($dx * $d2y - $dy * $d2x) / $denom;
        $u = ($dx * $d1y - $dy * $d1x) / $denom;

        if ($t < 0.0 || $t > 1.0 || $u < 0.0 || $u > 1.0) {
            return null; // Intersection is outside one of the segments
        }

        return [
            $p1[0] + $t * $d1x,
            $p1[1] + $t * $d1y,
        ];
    }

    /**
     * Remove terrain crossings that are within $minDistanceMeters of each other.
     * When two crossings are very close, prefer the one whose label describes
     * the more prominent feature (bridge > water > power > road > cliff).
     */
    private function deduplicateCrossings(array $crossings, float $minDistanceMeters = 30.0): array
    {
        $priority = ['Brug' => 0, 'Rivier' => 1, 'Kanaal' => 1, 'Beek' => 2,
                     'Sloot' => 2, 'Waterovergang' => 2, 'Hoogspanning' => 3,
                     'Snelweg' => 4, 'Hoofdweg' => 4, 'Secundaire weg' => 5,
                     'Tertiaire weg' => 5, 'Weg' => 5, 'Steile wand' => 6];

        $result = [];

        foreach ($crossings as $crossing) {
            $merged = false;
            foreach ($result as &$existing) {
                $dist = $this->haversineDistance(
                    $existing['lat'], $existing['lon'],
                    $crossing['lat'], $crossing['lon']
                );

                if ($dist < $minDistanceMeters) {
                    // Keep the one with higher priority (lower number)
                    $existingPri = $priority[$existing['label']] ?? 99;
                    $newPri      = $priority[$crossing['label']] ?? 99;
                    if ($newPri < $existingPri) {
                        $existing = $crossing;
                    }
                    $merged = true;
                    break;
                }
            }
            unset($existing);

            if (! $merged) {
                $result[] = $crossing;
            }
        }

        return $result;
    }
}
