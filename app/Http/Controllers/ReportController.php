<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Report;
use App\Models\ReportRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    use \App\Http\Traits\AuthorizesMapAccess;
    use \App\Http\Traits\ResolvesArrivalTasks;
    // ── Autorisatie ──────────────────────────────────────────────────────────
    // Gemarkeerde gebieden zijn gedeeld op kaartniveau: de eigenaar en elke
    // geaccepteerde collaborator ziet ze; bewerken/verwijderen vereist een niet-
    // viewer-rol. Reports zonder map_id blijven privé aan de maker.

    private function mapAccess(Map $map, bool $editorRequired = false): bool
    {
        return $this->authorizeMapAccess($map, $editorRequired);
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
     * Gemarkeerde gebieden uit een waarde in plaats van getekend: een MGRS-grid
     * (vierkante cel) of een straal rond een punt. Het vlak zelf staat in
     * metadata.saluteGeometry; metadata.saluteShape onthoudt de waarde zodat de
     * app het grid of de straal later kan aanpassen. Alleen als hij aanwezig is
     * wordt hij gecontroleerd; oudere en getekende gebieden hebben hem niet.
     *
     * Bewust één regel op `metadata` zelf: geneste regels (metadata.saluteShape.*)
     * zouden validated() de rest van metadata laten weggooien.
     */
    private function saluteShapeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value) || ($value['saluteShape'] ?? null) === null) {
                return;
            }
            $check = Validator::make(['metadata' => ['saluteShape' => $value['saluteShape']]], [
                'metadata.saluteShape' => 'array',
                'metadata.saluteShape.kind' => 'required|in:grid,circle',
                'metadata.saluteShape.grid' => ['required_if:metadata.saluteShape.kind,grid', 'nullable', 'string', 'max:24',
                    'regex:/^\d{1,2}[C-HJ-NP-X][A-HJ-NP-Z][A-HJ-NP-V](\d{2}){0,5}$/'],
                'metadata.saluteShape.size' => 'nullable|numeric|min:1|max:100000',
                'metadata.saluteShape.radius' => 'required_if:metadata.saluteShape.kind,circle|nullable|numeric|min:1|max:500000',
                'metadata.saluteShape.center' => 'required_if:metadata.saluteShape.kind,circle|nullable|array|size:2',
                'metadata.saluteShape.center.0' => 'numeric|between:-180,180',
                'metadata.saluteShape.center.1' => 'numeric|between:-90,90',
            ]);
            foreach ($check->errors()->all() as $message) {
                $fail($message);
            }
        };
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
            'client_request_id' => 'nullable|string|max:100',
            'map_id' => 'nullable|uuid',
            // Vrije string i.p.v. vaste enum: gebruikers kunnen eigen
            // meldingsformulieren (categorieën) aanmaken, net als `type` al vrij is.
            'category' => 'required|string|max:50',
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
            'metadata' => ['nullable', 'array', $this->saluteShapeRule()],
            'status' => 'nullable|string|in:active,resolved,archived',
        ]);

        $attributes = [
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
        ];

        // firstOrCreate handles a concurrent retry using the unique index.
        $report = !empty($validated['client_request_id'])
            ? Report::firstOrCreate(['user_id' => Auth::id(), 'client_request_id' => $validated['client_request_id']], $attributes)
            : Report::create($attributes);
        if ($report->wasRecentlyCreated) $this->logRevision($report, 'created');

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
        return response()->json($this->withRevisions($report));
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
            'category' => 'sometimes|string|max:50',
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
            'metadata' => ['nullable', 'array', $this->saluteShapeRule()],
            'status' => 'nullable|string|in:active,resolved,archived',
        ] + $this->arrivalTaskRules());

        // Taken: de server zet wie/wanneer afvinkte. In de wijzigingslog staan
        // alleen de titels, niet het afvinken (dat is een eigen regel).
        $taskChange = null;
        if (array_key_exists('tasks', $validated)) {
            $validated['tasks'] = $this->resolveArrivalTasks($report->tasks, $validated['tasks'] ?? []);
            $titlesBefore = $this->arrivalTaskTitles($report->tasks);
            $titlesAfter  = $this->arrivalTaskTitles($validated['tasks']);
            if ($titlesBefore !== $titlesAfter) {
                $taskChange = ['from' => $titlesBefore, 'to' => $titlesAfter];
            }
        }

        // Diff bepalen vóór de update zodat de wijzigingslog van→naar kent.
        $changes = $this->diffChanges($report, collect($validated)->except('tasks')->all());
        if ($taskChange) {
            $changes['tasks'] = $taskChange;
        }
        $report->update($validated);
        if (! empty($changes)) {
            $this->logRevision($report, 'updated', $changes);
        }

        return response()->json($this->withRevisions($report));
    }

    /**
     * Eén taak afvinken (of weer openzetten) zonder de rest van het gebied te
     * overschrijven: twee mensen die tegelijk verschillende taken afvinken
     * wissen elkaar zo niet uit.
     *
     * Toegang: iedereen met toegang tot de kaart, ook een 'viewer'. Afvinken is
     * het uitvoeren van het plan, niet het wijzigen ervan (net als bij waypoints).
     * POST /api/v1/reports/{id}/tasks/{taskId}  { done: bool }
     */
    public function setTaskDone(Request $request, $id, string $taskId)
    {
        $data = $request->validate(['done' => 'required|boolean']);

        $report = DB::transaction(function () use ($id, $taskId, $data) {
            $report = Report::lockForUpdate()->findOrFail($id);

            if (! $this->reportAccess($report)) {
                abort(403, 'Geen toegang tot dit gebied.');
            }

            $tasks = $report->tasks ?: [];
            $index = collect($tasks)->search(fn ($t) => ($t['id'] ?? null) === $taskId);
            abort_if($index === false, 404, 'Taak niet gevonden.');

            $title = (string) ($tasks[$index]['title'] ?? '');
            $tasks[$index]['done'] = (bool) $data['done'];
            $report->update(['tasks' => $this->resolveArrivalTasks($report->tasks, $tasks)]);

            $this->logRevision($report, $data['done'] ? 'task_done' : 'task_reopened', ['task' => ['title' => $title]]);
            return $report;
        });

        return response()->json($this->withRevisions($report));
    }

    /** Het gebied als array, met de wijzigingslog (wie/wat/wanneer) erbij. */
    private function withRevisions(Report $report): array
    {
        $report->setRelation('revisions', $report->revisions()->with('user')->get());
        $data = $report->toArray();
        $data['revisions'] = $report->revisions->map->toApiArray()->values();

        return $data;
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
