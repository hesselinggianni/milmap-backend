<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Report;
use App\Models\ReportRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    // ── Autorisatie ──────────────────────────────────────────────────────────
    // Gemarkeerde gebieden zijn gedeeld op kaartniveau: de eigenaar en elke
    // geaccepteerde collaborator ziet ze; bewerken/verwijderen vereist een niet-
    // viewer-rol. Reports zonder map_id blijven privé aan de maker.

    private function mapAccess(Map $map, bool $editorRequired = false): bool
    {
        $userId = Auth::id();
        if ((string) $map->owner_id === (string) $userId) return true;

        $collab = $map->collaborators()
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->first();

        if (! $collab) return false;
        if ($editorRequired && $collab->role === 'viewer') return false;

        return true;
    }

    private function reportAccess(Report $report, bool $editorRequired = false): bool
    {
        if ($report->map_id) {
            $map = Map::find($report->map_id);
            if ($map) return $this->mapAccess($map, $editorRequired);
        }
        // Persoonlijk report (geen kaart) → alleen de maker.
        return (int) $report->user_id === (int) Auth::id();
    }

    /**
     * Bereken een veld-voor-veld diff voor de wijzigingslog: { veld: {from,to} }.
     */
    private function diffChanges(Report $report, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $newVal) {
            $oldVal = $report->getAttribute($key);
            if (json_encode($oldVal) !== json_encode($newVal)) {
                $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
            }
        }
        return $changes;
    }

    private function logRevision(Report $report, string $action, ?array $changes = null): void
    {
        ReportRevision::create([
            'report_id' => $report->id,
            'user_id'   => Auth::id(),
            'action'    => $action,
            'changes'   => $changes ?: null,
        ]);
    }

    /**
     * Get all reports for current user (optionally filtered by map)
     */
    public function index(Request $request)
    {
        $query = Report::where('user_id', Auth::id());

        // Filter by category if provided
        if ($request->has('category')) {
            $query->where('category', $request->query('category'));
        }

        // Filter by map if provided
        if ($request->has('map_id')) {
            $query->where('map_id', $request->query('map_id'));
        }

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        // Get recent reports (last 30 days by default)
        $days = $request->query('days', 30);
        $query->where('created_at', '>=', now()->subDays($days));

        return response()->json(
            $query->orderBy('created_at', 'desc')->get()
        );
    }

    /**
     * Store a new report
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'map_id' => 'nullable|uuid',
            'category' => 'required|string|in:threat,hazard,salute',
            'type' => 'required|string|max:50',
            'subtype' => 'nullable|string|max:50',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'urgency' => 'nullable|string|in:low,medium,high',
            'timing' => 'nullable|string|max:100',
            'size' => 'nullable|string|max:20',
            'count' => 'nullable|integer',
            'activity' => 'nullable|array',
            'equipment' => 'nullable|array',
            'risk' => 'nullable|string|in:Laag,Middel,Hoog',
            'hazardType' => 'nullable|string|max:100',
            'avalancheLevel' => 'nullable|integer|between:1,5',
            'roadCondition' => 'nullable|string|max:100',
            'weatherCondition' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'metadata' => 'nullable|array',
            'status' => 'nullable|string|in:active,resolved,archived',
        ]);

        $report = Report::create([
            'map_id' => $validated['map_id'] ?? null,
            'user_id' => Auth::id(),
            'category' => $validated['category'],
            'type' => $validated['type'],
            'subtype' => $validated['subtype'] ?? null,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'urgency' => $validated['urgency'] ?? null,
            'timing' => $validated['timing'] ?? null,
            'size' => $validated['size'] ?? null,
            'count' => $validated['count'] ?? null,
            'activity' => $validated['activity'] ?? null,
            'equipment' => $validated['equipment'] ?? null,
            'risk' => $validated['risk'] ?? null,
            'hazardType' => $validated['hazardType'] ?? null,
            'avalancheLevel' => $validated['avalancheLevel'] ?? null,
            'roadCondition' => $validated['roadCondition'] ?? null,
            'weatherCondition' => $validated['weatherCondition'] ?? null,
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        $this->logRevision($report, 'created');

        return response()->json($report, 201);
    }

    /**
     * Get a single report
     */
    public function show($id)
    {
        $report = Report::findOrFail($id);

        if (! $this->reportAccess($report)) {
            abort(403, 'Geen toegang tot dit gebied.');
        }

        // Wijzigingslog meesturen (met wie/wat/wanneer).
        $report->setRelation('revisions', $report->revisions()->with('user')->get());
        $data = $report->toArray();
        $data['revisions'] = $report->revisions->map->toApiArray()->values();

        return response()->json($data);
    }

    /**
     * Update a report
     */
    public function update(Request $request, $id)
    {
        $report = Report::findOrFail($id);

        if (! $this->reportAccess($report, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op dit gebied.');
        }

        $validated = $request->validate([
            'category' => 'sometimes|string|in:threat,hazard,salute',
            'type' => 'sometimes|string|max:50',
            'subtype' => 'nullable|string|max:50',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'urgency' => 'nullable|string|in:low,medium,high',
            'timing' => 'nullable|string|max:100',
            'size' => 'nullable|string|max:20',
            'count' => 'nullable|integer',
            'activity' => 'nullable|array',
            'equipment' => 'nullable|array',
            'risk' => 'nullable|string|in:Laag,Middel,Hoog',
            'hazardType' => 'nullable|string|max:100',
            'avalancheLevel' => 'nullable|integer|between:1,5',
            'roadCondition' => 'nullable|string|max:100',
            'weatherCondition' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'metadata' => 'nullable|array',
            'status' => 'nullable|string|in:active,resolved,archived',
        ]);

        // Diff bepalen vóór de update zodat de wijzigingslog van→naar kent.
        $changes = $this->diffChanges($report, $validated);
        $report->update($validated);
        if (! empty($changes)) {
            $this->logRevision($report, 'updated', $changes);
        }

        $report->setRelation('revisions', $report->revisions()->with('user')->get());
        $data = $report->toArray();
        $data['revisions'] = $report->revisions->map->toApiArray()->values();

        return response()->json($data);
    }

    /**
     * Delete a report
     */
    public function destroy($id)
    {
        $report = Report::findOrFail($id);

        if (! $this->reportAccess($report, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op dit gebied.');
        }

        $report->delete(); // revisies vervallen via cascade

        return response()->json(['message' => 'Report deleted']);
    }

    /**
     * Get reports by category
     */
    public function getByCategory(Request $request, $category)
    {
        return response()->json(
            Report::where('user_id', Auth::id())
                ->where('category', $category)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    /**
     * Get reports for a specific map
     */
    public function getByMap(Request $request, $mapId)
    {
        $map = Map::find($mapId);
        // Gedeeld op de kaart: elke deelnemer ziet alle gebieden. Bestaat de
        // kaart niet (server nog niet gesynct) of geen toegang → val terug op de
        // eigen reports zodat lokale/privé-kaarten blijven werken.
        if ($map && $this->mapAccess($map)) {
            $reports = Report::where('map_id', $mapId)
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $reports = Report::where('user_id', Auth::id())
                ->where('map_id', $mapId)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return response()->json($reports);
    }
}
