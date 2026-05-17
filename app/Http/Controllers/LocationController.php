<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocationController extends Controller
{
    /**
     * Get all locations for a specific map
     */
    public function index($mapId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        return response()->json(
            Location::where('map_id', $mapId)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    /**
     * Create a new location marker
     */
    public function store(Request $request, $mapId)
    {
        // Validate that map belongs to user
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
        ]);

        $location = Location::create([
            'map_id' => $mapId,
            'user_id' => Auth::id(),
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'color' => $validated['color'] ?? '#FF0000',
            'icon' => $validated['icon'] ?? 'marker',
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json($location, 201);
    }

    /**
     * Get a specific location
     */
    public function show($mapId, $locationId)
    {
        // Verify user owns the map
        Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $location = Location::where('id', $locationId)
            ->where('map_id', $mapId)
            ->firstOrFail();

        return response()->json($location);
    }

    /**
     * Update a location
     */
    public function update(Request $request, $mapId, $locationId)
    {
        // Verify user owns the map
        Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $location = Location::where('id', $locationId)
            ->where('map_id', $mapId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
        ]);

        $location->update($validated);

        return response()->json($location);
    }

    /**
     * Delete a location
     */
    public function destroy($mapId, $locationId)
    {
        // Verify user owns the map
        Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $location = Location::where('id', $locationId)
            ->where('map_id', $mapId)
            ->firstOrFail();

        $location->delete();

        return response()->json(['message' => 'Location deleted']);
    }

    /**
     * Bulk delete locations for a map
     */
    public function clear($mapId)
    {
        Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        Location::where('map_id', $mapId)->delete();

        return response()->json(['message' => 'All locations cleared']);
    }
}
