<?php
namespace App\Http\Controllers;

use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapController extends Controller
{
    /**
     * Alle maps van ingelogde user
     */
    public function index()
    {
        return response()->json(
            Map::where('owner_id', Auth::id())
                ->latest()
                ->get()
        );
    }

    /**
     * Extra alias voor frontend
     */
    public function myMaps()
    {
        return response()->json(
            Map::where('owner_id', Auth::id())
                ->latest()
                ->get()
        );
    }

    /**
     * Single map (owner OR collaborator)
     */
    public function show($id)
    {
        $map = Map::findOrFail($id);

        // Check if user has access (via policy)
        $this->authorize('view', $map);

        return response()->json($map);
    }

    /**
     * Create map
     */
    public function store(Request $request)
    {
        $map = Map::create([
            'title' => $request->title,
            'settings' => $request->settings ?? [
                'locationformat' => 'mgrs',
                'maxZoom' => 100,
                'mapExtent' => null,
                'language' => 'nl',
                'useCurrentCoordsAsBounds' => false,
            ],
            'status' => $request->status ?? 'active',
            'owner_id' => Auth::id(),
        ]);

        return response()->json($map, 201);
    }

    /**
     * Update map (owner OR collaborator)
     */
    public function update(Request $request, $id)
    {
        $map = Map::findOrFail($id);

        // Check if user has access (via policy)
        $this->authorize('update', $map);

        // Prepare settings: merge new settings with existing ones
        $updatedData = [
            'title' => $request->title ?? $map->title,
            'status' => $request->status ?? $map->status,
        ];

        // If settings are provided, merge them with existing settings
        if ($request->has('settings')) {
            $currentSettings = is_array($map->settings) ? $map->settings : [];
            $newSettings = is_array($request->settings) ? $request->settings : [];
            $updatedData['settings'] = array_merge($currentSettings, $newSettings);
        }

        $map->update($updatedData);

        return response()->json($map);
    }

    /**
     * Delete map (owner only)
     */
    public function destroy($id)
    {
        $map = Map::findOrFail($id);

        // Check if user is owner (via policy)
        $this->authorize('delete', $map);

        $map->delete();

        return response()->json(['message' => 'Deleted']);
    }
}