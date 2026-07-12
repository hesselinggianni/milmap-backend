<?php

namespace App\Http\Controllers;

use App\Models\ExerciseObjective;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Doelen (Objectives) van een oefening: aanmaken/beheren door de commandant,
 * inzien door deelnemers (met de eigen actie-status per doel).
 *
 * Zie docs/oefening-modus.md.
 */
class ExerciseObjectiveController extends Controller
{
    /**
     * Doelen van de oefening, elk met de actie-status van de huidige gebruiker.
     * GET /api/v1/missions/{id}/exercise/objectives
     */
    public function index($id)
    {
        $mission = $this->accessibleMission($id);
        $uid = Auth::id();

        // Eén query voor de eigen submissions per doel (meest recente status).
        $mySubs = $mission->submissions()
            ->where('user_id', $uid)
            ->get()
            ->groupBy('objective_id');

        $objectives = $mission->objectives()
            ->orderBy('created_at')
            ->get()
            ->map(function ($o) use ($mySubs) {
                $arr = $this->present($o);
                $sub = ($mySubs[$o->id] ?? collect())->sortByDesc('submitted_at')->first();
                $arr['my_status'] = $sub?->status; // null | pending | approved | rejected
                return $arr;
            });

        return response()->json(['objectives' => $objectives]);
    }

    /**
     * Doel aanmaken (commander).
     * POST /api/v1/missions/{id}/exercise/objectives
     */
    public function store(Request $request, $id)
    {
        $mission = $this->commanderMission($id);

        $data = $this->validateObjective($request, $mission);

        $objective = ExerciseObjective::create(array_merge($data, ['mission_id' => $mission->id]));

        return response()->json(['objective' => $this->present($objective)], 201);
    }

    /**
     * Doel bijwerken (commander).
     * PUT /api/v1/missions/{id}/exercise/objectives/{objId}
     */
    public function update(Request $request, $id, $objId)
    {
        $mission = $this->commanderMission($id);

        $objective = $mission->objectives()->findOrFail($objId);
        $objective->update($this->validateObjective($request, $mission, true));

        return response()->json(['objective' => $this->present($objective)]);
    }

    /**
     * Doel verwijderen (commander).
     * DELETE /api/v1/missions/{id}/exercise/objectives/{objId}
     */
    public function destroy($id, $objId)
    {
        $mission = $this->commanderMission($id);

        $mission->objectives()->findOrFail($objId)->delete();

        return response()->json(['message' => 'Doel verwijderd.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function exerciseMission($id): Mission
    {
        $mission = Mission::with(['participants', 'exerciseSite'])->findOrFail($id);
        if (! $mission->isExercise()) {
            abort(404, 'Deze missie is geen oefening.');
        }
        return $mission;
    }

    /** Elke deelnemer/staf van de oefening mag de doelen inzien. */
    protected function accessibleMission($id): Mission
    {
        $mission = $this->exerciseMission($id);
        if ($mission->exerciseRoleFor(Auth::id()) === null) {
            abort(403, 'Meld je eerst aan bij deze oefening.');
        }
        return $mission;
    }

    protected function commanderMission($id): Mission
    {
        $mission = $this->exerciseMission($id);
        if (! $mission->isExerciseCommander(Auth::id())) {
            abort(403, 'Alleen een commandant kan doelen beheren.');
        }
        return $mission;
    }

    protected function validateObjective(Request $request, Mission $mission, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        $data = $request->validate([
            'title'        => "$req|string|max:191",
            'description'  => 'nullable|string|max:5000',
            'type'         => [$partial ? 'sometimes' : 'required', Rule::in(['reach_location', 'hold_location', 'timed_task', 'free_action'])],
            'faction_id'   => ['nullable', Rule::exists('exercise_factions', 'id')->where('mission_id', $mission->id)],
            'target_lat'   => 'nullable|numeric|between:-90,90',
            'target_lng'   => 'nullable|numeric|between:-180,180',
            'radius_m'     => 'nullable|integer|min:1|max:100000',
            'hold_seconds' => 'nullable|integer|min:1|max:86400',
            'window_start' => 'nullable|date',
            'window_end'   => 'nullable|date|after_or_equal:window_start',
            'points'       => 'nullable|integer|min:0|max:100000',
            'verification' => ['sometimes', Rule::in(['auto', 'controller', 'both'])],
            'status'       => ['sometimes', Rule::in(['active', 'archived'])],
        ]);

        return $data;
    }

    protected function present(ExerciseObjective $o): array
    {
        return [
            'id'           => $o->id,
            'faction_id'   => $o->faction_id,
            'title'        => $o->title,
            'description'  => $o->description,
            'type'         => $o->type,
            'target_lat'   => $o->target_lat,
            'target_lng'   => $o->target_lng,
            'radius_m'     => $o->radius_m,
            'hold_seconds' => $o->hold_seconds,
            'window_start' => $o->window_start?->toIso8601String(),
            'window_end'   => $o->window_end?->toIso8601String(),
            'points'       => $o->points,
            'verification' => $o->verification,
            'status'       => $o->status,
        ];
    }
}
