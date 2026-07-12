<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Scorebord van een oefening: punten per deelnemer én per partij, afgeleid uit
 * goedgekeurde acties (exercise_submissions.awarded_points). Zie docs/oefening-modus.md.
 */
class ExerciseScoreController extends Controller
{
    /**
     * GET /api/v1/missions/{id}/exercise/scores
     */
    public function index($id)
    {
        $mission = Mission::with(['participants.user:id,first_name,last_name', 'factions'])->findOrFail($id);
        if (! $mission->isExercise()) {
            abort(404, 'Deze missie is geen oefening.');
        }
        if ($mission->exerciseRoleFor(Auth::id()) === null) {
            abort(403, 'Meld je eerst aan bij deze oefening.');
        }

        // Somtellingen uit goedgekeurde acties, in twee groep-queries.
        $perUser = DB::table('exercise_submissions')
            ->select('user_id', DB::raw('SUM(COALESCE(awarded_points,0)) as pts'))
            ->where('mission_id', $mission->id)
            ->where('status', 'approved')
            ->groupBy('user_id')
            ->pluck('pts', 'user_id');

        $perFaction = DB::table('exercise_submissions')
            ->select('faction_id', DB::raw('SUM(COALESCE(awarded_points,0)) as pts'))
            ->where('mission_id', $mission->id)
            ->where('status', 'approved')
            ->groupBy('faction_id')
            ->pluck('pts', 'faction_id');

        // Spelers: alle deelnemers (ook met 0 punten), gesorteerd op score.
        $players = $mission->participants
            ->map(fn ($p) => [
                'user_id'    => (int) $p->user_id,
                'name'       => $p->user?->full_name ?? ('#' . $p->user_id),
                'faction_id' => $p->faction_id,
                'points'     => (int) ($perUser[$p->user_id] ?? 0),
            ])
            ->sortByDesc('points')
            ->values();

        // Partijen: alle facties (ook met 0), gesorteerd op score.
        $factions = $mission->factions
            ->map(fn ($f) => [
                'faction_id' => $f->id,
                'name'       => $f->name,
                'color'      => $f->color,
                'points'     => (int) ($perFaction[$f->id] ?? 0),
            ])
            ->sortByDesc('points')
            ->values();

        return response()->json([
            'players'  => $players,
            'factions' => $factions,
        ]);
    }
}
