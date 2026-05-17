<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
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

        return response()->json($report, 201);
    }

    /**
     * Get a single report
     */
    public function show($id)
    {
        $report = Report::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return response()->json($report);
    }

    /**
     * Update a report
     */
    public function update(Request $request, $id)
    {
        $report = Report::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

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

        $report->update($validated);

        return response()->json($report);
    }

    /**
     * Delete a report
     */
    public function destroy($id)
    {
        $report = Report::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $report->delete();

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
        return response()->json(
            Report::where('user_id', Auth::id())
                ->where('map_id', $mapId)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }
}
