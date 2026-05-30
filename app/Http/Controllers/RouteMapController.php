<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\RouteMap;
use App\Models\MapShare;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RouteMapController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        // Get all maps the user has access to (owner + collaborated)
        $mapIds = Map::where('owner_id', auth()->id())
            ->orWhere(function ($query) {
                $query->whereHas('collaborators', function ($q) {
                    $q->where('user_id', auth()->id())
                        ->where('status', 'accepted');
                });
            })
            ->pluck('id');

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

        // Check if user has access to the map
        $map = $routeMap->map;
        if (((int) $map->owner_id) !== auth()->id()) {
            // Check if user is an accepted collaborator
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this route map');
            }
        }

        return response()->json($routeMap);
    }

    public function getByMapId(Request $request, string $mapId)
    {
        $map = Map::findOrFail($mapId);

        // Check for share token (unauthenticated public access)
        $shareToken = $request->query('share_token');
        if ($shareToken) {
            $share = MapShare::active()
                ->where('token', $shareToken)
                ->where('map_id', $mapId)
                ->first();

            if (!$share) {
                abort(403, 'Invalid or expired share token');
            }
            // Valid share token - allow access
        } else {
            // Check if user has access to the map
            if (!auth()->check() || ((int) $map->owner_id) !== auth()->id()) {
                if (!$map->collaborators()
                    ->where('user_id', auth()->id())
                    ->where('status', 'accepted')
                    ->exists()) {
                    abort(403, 'Unauthorized access to this map');
                }
            }
        }

        $routeMaps = RouteMap::with(['owner', 'map'])
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
        ]);

        // Check if user has access to the map
        $map = Map::findOrFail($validated['map_id']);
        if (((int) $map->owner_id) !== auth()->id()) {
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this map');
            }
        }

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

        // Check if user has access to the map
        $map = $routeMap->map;
        if (((int) $map->owner_id) !== auth()->id()) {
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this route map');
            }
        }

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

            'bijlage' => ['nullable', 'array'],
            'callsign' => ['nullable', 'string'],
            'eenheid' => ['nullable', 'string'],
            'sterkte' => ['nullable', 'integer'],
            'kaartblad' => ['nullable', 'string'],
            'vtv_vta' => ['nullable', 'string'],
        ]);

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

        return response()->json([
            'message' => 'RouteMap bijgewerkt',
            'data' => $routeMap,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESTROY
    |--------------------------------------------------------------------------
    */

    public function destroy(string $id)
    {
        $routeMap = RouteMap::findOrFail($id);

        // Check if user has access to the map
        $map = $routeMap->map;
        if (((int) $map->owner_id) !== auth()->id()) {
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this route map');
            }
        }

        $routeMap->delete();

        return response()->json([
            'message' => 'RouteMap verwijderd',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE UPLOAD
    |--------------------------------------------------------------------------
    */

    /**
     * Upload an image for a checkpoint
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

        // Check access
        $map = $routeMap->map;
        if (((int) $map->owner_id) !== auth()->id()) {
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this route map');
            }
        }

        try {
            // Get locations array
            $locations = $routeMap->locations ?? [];

            // Find the checkpoint location
            $checkpointIndex = null;
            foreach ($locations as $index => $loc) {
                if ($loc['id'] === $checkpointId) {
                    $checkpointIndex = $index;
                    break;
                }
            }

            if ($checkpointIndex === null) {
                return response()->json([
                    'message' => 'Checkpoint niet gevonden',
                ], 404);
            }

            // Get ImageUploadService
            $uploadService = new \App\Services\ImageUploadService();

            // Upload the image
            $uploadResult = $uploadService->store(
                $request->file('image'),
                $id,
                $checkpointId
            );

            // Delete old image if it exists
            if (!empty($locations[$checkpointIndex]['flyoverImage'])) {
                $oldFilename = $uploadService->getFilenameFromUrl(
                    $locations[$checkpointIndex]['flyoverImage']
                );
                if ($oldFilename) {
                    $uploadService->delete($id, $oldFilename);
                }
            }

            // Update location with new image URL
            $locations[$checkpointIndex]['flyoverImage'] = $uploadResult['url'];

            // Save route map
            $routeMap->update(['locations' => $locations]);

            // Broadcast update for real-time sync
            // broadcast(new \App\Events\RouteMapUpdated($routeMap))->toOthers();

            return response()->json([
                'url' => $uploadResult['url'],
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

        // Check access
        $map = $routeMap->map;
        if (((int) $map->owner_id) !== auth()->id()) {
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this route map');
            }
        }

        try {
            $locations = $routeMap->locations ?? [];

            // Find the checkpoint location
            $checkpointIndex = null;
            foreach ($locations as $index => $loc) {
                if ($loc['id'] === $checkpointId) {
                    $checkpointIndex = $index;
                    break;
                }
            }

            if ($checkpointIndex === null) {
                return response()->json([
                    'message' => 'Checkpoint niet gevonden',
                ], 404);
            }

            // Delete file from storage
            if (!empty($locations[$checkpointIndex]['flyoverImage'])) {
                $uploadService = new \App\Services\ImageUploadService();
                $filename = $uploadService->getFilenameFromUrl(
                    $locations[$checkpointIndex]['flyoverImage']
                );
                if ($filename) {
                    $uploadService->delete($id, $filename);
                }
            }

            // Clear flyoverImage field
            $locations[$checkpointIndex]['flyoverImage'] = null;

            // Save route map
            $routeMap->update(['locations' => $locations]);

            // Broadcast update for real-time sync
            // broadcast(new \App\Events\RouteMapUpdated($routeMap))->toOthers();

            return response()->json([
                'message' => 'Afbeelding verwijderd',
                'location' => $locations[$checkpointIndex],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}