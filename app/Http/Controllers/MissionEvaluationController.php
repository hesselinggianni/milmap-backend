<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionEvaluation;
use App\Models\MissionTrack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MissionEvaluationController extends Controller
{
    /**
     * Get the authenticated user's own evaluation for this mission.
     * GET /api/v1/missions/{id}/evaluations/mine
     */
    public function mine($missionId)
    {
        $userId = Auth::id();
        $mission = Mission::findOrFail($missionId);

        if (! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $eval = MissionEvaluation::where('mission_id', $mission->id)
            ->where('user_id', $userId)
            ->first();

        return response()->json(['evaluation' => $eval ? $this->presentEval($eval) : null]);
    }

    /**
     * Submit (create or update) the authenticated user's evaluation.
     * POST /api/v1/missions/{id}/evaluations
     */
    public function store(Request $request, $missionId)
    {
        $userId = Auth::id();
        $mission = Mission::findOrFail($missionId);

        if (! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'rating'        => ['nullable', 'integer', 'between:1,5'],
            'goal_achieved' => ['nullable', 'in:yes,partial,no'],
            'went_well'     => ['nullable', 'string', 'max:3000'],
            'went_wrong'    => ['nullable', 'string', 'max:3000'],
            'notes'         => ['nullable', 'string', 'max:3000'],
            'submitted'     => ['nullable', 'boolean'],
        ]);

        $eval = MissionEvaluation::updateOrCreate(
            ['mission_id' => $mission->id, 'user_id' => $userId],
            [
                'rating'        => $data['rating'] ?? null,
                'goal_achieved' => $data['goal_achieved'] ?? null,
                'went_well'     => $data['went_well'] ?? null,
                'went_wrong'    => $data['went_wrong'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'submitted_at'  => ($data['submitted'] ?? false) ? now() : null,
            ]
        );

        return response()->json(['evaluation' => $this->presentEval($eval)], 201);
    }

    /**
     * All evaluations for a mission (owner/admin/editor only).
     * GET /api/v1/missions/{id}/evaluations
     */
    public function index($missionId)
    {
        $userId = Auth::id();
        $mission = Mission::findOrFail($missionId);

        if (! $mission->canEdit($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $evals = MissionEvaluation::with('user:id,first_name,last_name,email')
            ->where('mission_id', $mission->id)
            ->orderBy('submitted_at', 'desc')
            ->get();

        // Enrich each eval with basic track stats
        $result = $evals->map(function ($eval) use ($mission) {
            $presented = $this->presentEval($eval);

            // Attach track summary (distance + climb) if GPS data exists
            $trackCount = MissionTrack::where('mission_id', $mission->id)
                ->where('user_id', $eval->user_id)
                ->count();

            $presented['has_track'] = $trackCount > 0;
            $presented['track_points'] = $trackCount;

            return $presented;
        });

        return response()->json(['evaluations' => $result]);
    }

    private function presentEval(MissionEvaluation $eval): array
    {
        $user = $eval->relationLoaded('user') ? $eval->user : null;

        return [
            'id'            => $eval->id,
            'mission_id'    => $eval->mission_id,
            'user_id'       => $eval->user_id,
            'user_name'     => $user
                ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email)
                : null,
            'rating'        => $eval->rating,
            'goal_achieved' => $eval->goal_achieved,
            'went_well'     => $eval->went_well,
            'went_wrong'    => $eval->went_wrong,
            'notes'         => $eval->notes,
            'submitted_at'  => $eval->submitted_at,
            'is_submitted'  => $eval->submitted_at !== null,
        ];
    }
}
