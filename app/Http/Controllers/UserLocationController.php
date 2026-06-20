<?php

namespace App\Http\Controllers;

use App\Events\UserLocationUpdated;
use App\Events\UserLocationRemoved;
use App\Models\Map;
use App\Models\UserLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserLocationController extends Controller
{
    /**
     * Update user's location for a specific map.
     * Throttled to prevent excessive updates.
     */
    public function update(Request $request, string $mapId)
    {
        // Verify user has access to this map
        $map = Map::findOrFail($mapId);

        if (!$map->users->contains(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Validate location data
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric|between:0,360',
            'speed' => 'nullable|numeric|min:0',
            'device_id' => 'nullable|string|max:255',
            'route_map_id' => 'nullable|string|max:64',
            'route_map_title' => 'nullable|string|max:255',
        ]);

        // Throttle updates: only allow 1 per second per user per map
        $lastUpdate = UserLocation::where('map_id', $mapId)
            ->where('user_id', Auth::id())
            ->select('updated_at')
            ->first();

        if ($lastUpdate && $lastUpdate->updated_at->isAfter(now()->subSecond())) {
            return response()->json(['error' => 'Too many updates'], 429);
        }

        // Create or update location
        $location = UserLocation::updateOrCreate(
            ['map_id' => $mapId, 'user_id' => Auth::id()],
            array_merge($validated, [
                'last_updated_at' => now(),
                'device_id' => $request->get('device_id', null),
            ])
        );

        // Broadcast the update
        UserLocationUpdated::dispatch($location);

        return response()->json([
            'success' => true,
            'location' => $this->formatLocation($location),
        ]);
    }

    /**
     * Get all active user locations for a specific map.
     * Filters to only fresh locations (updated within 2 minutes).
     */
    public function getLocations(string $mapId)
    {
        // Verify user has access to this map
        $map = Map::findOrFail($mapId);

        if (!$map->users->contains(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get all fresh locations for this map with user data
        $locations = UserLocation::where('map_id', $mapId)
            ->where('last_updated_at', '>', now()->subMinutes(2))
            ->with('user:id,name,email,avatar_path')
            ->get()
            ->map(fn($loc) => $this->formatLocation($loc));

        return response()->json([
            'success' => true,
            'locations' => $locations,
            'map_id' => $mapId,
        ]);
    }

    /**
     * Stop sharing location - remove user's location record.
     */
    public function stopSharing(string $mapId)
    {
        // Verify user has access to this map
        $map = Map::findOrFail($mapId);

        if (!$map->users->contains(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $location = UserLocation::where('map_id', $mapId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$location) {
            return response()->json(['error' => 'Location not found'], 404);
        }

        $userName = Auth::user()->name;
        $location->delete();

        // Broadcast the removal
        UserLocationRemoved::dispatch($mapId, Auth::id(), $userName);

        return response()->json([
            'success' => true,
            'message' => 'Location sharing stopped',
        ]);
    }

    /**
     * Cleanup: Remove stale locations (older than 5 minutes).
     * Can be run via scheduler or manually.
     */
    public function cleanup()
    {
        $deleted = UserLocation::where('last_updated_at', '<', now()->subMinutes(5))
            ->delete();

        return response()->json([
            'success' => true,
            'deleted_count' => $deleted,
            'message' => "Deleted $deleted stale location records",
        ]);
    }

    /**
     * Format location data for API response.
     */
    private function formatLocation(UserLocation $location): array
    {
        return [
            'user_id' => $location->user_id,
            'user_name' => $location->user->name,
            'avatar_url' => $location->user->avatar_url,
            'map_id' => $location->map_id,
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy' => $location->accuracy ? (float) $location->accuracy : null,
            'heading' => $location->heading ? (float) $location->heading : null,
            'speed' => $location->speed ? (float) $location->speed : null,
            'route_map_id' => $location->route_map_id,
            'route_map_title' => $location->route_map_title,
            'last_updated_at' => $location->last_updated_at->toIso8601String(),
            'device_id' => $location->device_id,
        ];
    }
}
