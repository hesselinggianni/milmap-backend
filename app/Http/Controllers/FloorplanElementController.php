<?php

namespace App\Http\Controllers;

use App\Events\FloorplanElementChanged;
use App\Models\FloorplanElement;
use App\Models\Map;
use App\Models\WaypointFloorplan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FloorplanElementController extends Controller
{
    use \App\Http\Traits\AuthorizesMapAccess;

    private function mapFor(WaypointFloorplan $floorplan): Map
    {
        $floorplan->loadMissing('waypoint');
        return Map::findOrFail($floorplan->waypoint->map_id);
    }

    private const TYPES = ['wall', 'room', 'door', 'window', 'stairs', 'symbol', 'text', 'freehand'];

    /**
     * Create an element and broadcast it live to everyone on the floorplan.
     * POST /api/v1/floorplans/{floorplanId}/elements
     * Body: { type, geometry, style?, label?, z_index? }
     */
    public function store(Request $request, $floorplanId)
    {
        $floorplan = WaypointFloorplan::findOrFail($floorplanId);
        $map = $this->mapFor($floorplan);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $data = $request->validate([
            'type'     => 'required|string|in:' . implode(',', self::TYPES),
            'geometry' => 'required|array',
            'style'    => 'nullable|array',
            'label'    => 'nullable|string|max:200',
            'z_index'  => 'nullable|integer',
        ]);

        $element = FloorplanElement::create([
            'floorplan_id' => $floorplan->id,
            'type'         => $data['type'],
            'geometry'     => $data['geometry'],
            'style'        => $data['style'] ?? [],
            'label'        => $data['label'] ?? null,
            'z_index'      => $data['z_index'] ?? 0,
            'created_by'   => Auth::id(),
        ]);

        broadcast(new FloorplanElementChanged($floorplan->id, $element->toClientArray(), 'created', Auth::id()))->toOthers();

        return response()->json(['element' => $element->toClientArray()], 201);
    }

    /**
     * Update an element (move/resize/restyle) and broadcast.
     * PUT /api/v1/floorplans/{floorplanId}/elements/{elementId}
     */
    public function update(Request $request, $floorplanId, $elementId)
    {
        $floorplan = WaypointFloorplan::findOrFail($floorplanId);
        $map = $this->mapFor($floorplan);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $element = FloorplanElement::where('floorplan_id', $floorplan->id)
            ->where('id', $elementId)
            ->firstOrFail();

        $data = $request->validate([
            'geometry' => 'sometimes|array',
            'style'    => 'sometimes|array',
            'label'    => 'nullable|string|max:200',
            'z_index'  => 'sometimes|integer',
        ]);

        $element->update($data);

        broadcast(new FloorplanElementChanged($floorplan->id, $element->toClientArray(), 'updated', Auth::id()))->toOthers();

        return response()->json(['element' => $element->toClientArray()]);
    }

    /**
     * Delete an element and broadcast.
     * DELETE /api/v1/floorplans/{floorplanId}/elements/{elementId}
     */
    public function destroy($floorplanId, $elementId)
    {
        $floorplan = WaypointFloorplan::findOrFail($floorplanId);
        $map = $this->mapFor($floorplan);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $element = FloorplanElement::where('floorplan_id', $floorplan->id)
            ->where('id', $elementId)
            ->firstOrFail();

        $clientData = $element->toClientArray();
        $element->delete();

        broadcast(new FloorplanElementChanged($floorplan->id, $clientData, 'deleted', Auth::id()))->toOthers();

        return response()->json(['message' => 'Element verwijderd.']);
    }
}
