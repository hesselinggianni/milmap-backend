<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\RouteMap;
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
        if ($map->owner_id !== auth()->id()) {
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

    public function getByMapId(string $mapId)
    {
        $map = Map::findOrFail($mapId);

        // Check if user has access to the map
        if ($map->owner_id !== auth()->id()) {
            if (!$map->collaborators()
                ->where('user_id', auth()->id())
                ->where('status', 'accepted')
                ->exists()) {
                abort(403, 'Unauthorized access to this map');
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

        ]);

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
        if ($map->owner_id !== auth()->id()) {
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
        if ($map->owner_id !== auth()->id()) {
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
}