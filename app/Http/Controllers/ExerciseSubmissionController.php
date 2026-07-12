<?php

namespace App\Http\Controllers;

use App\Models\ExerciseObjective;
use App\Models\ExerciseParticipant;
use App\Models\ExerciseSubmission;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Acties (Submissions): een deelnemer claimt een doel, een controleur keurt goed
 * of af. Goedkeuring kent awarded_points toe (default = de punten van het doel).
 *
 * Zie docs/oefening-modus.md.
 */
class ExerciseSubmissionController extends Controller
{
    /**
     * Een doel claimen (deelnemer). Optioneel met notitie, locatie en foto-bewijs.
     * POST /api/v1/missions/{id}/exercise/objectives/{objId}/submit
     */
    public function submit(Request $request, $id, $objId)
    {
        $mission = $this->participantMission($id);
        $objective = $mission->objectives()->findOrFail($objId);

        $data = $request->validate([
            'note' => 'nullable|string|max:2000',
            'lat'  => 'nullable|numeric|between:-90,90',
            'lng'  => 'nullable|numeric|between:-180,180',
            'photo' => 'nullable|image|max:8192',
        ]);

        // Voorkom dubbele claims: één openstaande/goedgekeurde actie per doel per speler.
        $existing = ExerciseSubmission::where('objective_id', $objective->id)
            ->where('user_id', Auth::id())
            ->whereIn('status', ['pending', 'approved'])
            ->first();
        if ($existing) {
            return response()->json([
                'message' => $existing->status === 'approved'
                    ? 'Je hebt dit doel al gehaald.'
                    : 'Je actie voor dit doel wacht al op beoordeling.',
                'code'       => 'duplicate_submission',
                'submission' => $this->present($existing),
            ], 422);
        }

        // Geofence-controle voor locatie-doelen. Bij verification 'auto'/'both'
        // moet de deelnemer binnen de radius én binnen het tijdvenster zijn.
        $autoApprove = false;
        if (in_array($objective->type, ['reach_location', 'hold_location'], true)
            && in_array($objective->verification, ['auto', 'both'], true)) {

            $geo = $this->geofenceCheck($objective, $data['lat'] ?? null, $data['lng'] ?? null);
            if (! $geo['ok']) {
                return response()->json([
                    'message' => $geo['message'],
                    'code'    => $geo['code'],
                ], 422);
            }

            // 'auto' sluit de loop zonder controleur; 'both' laat de controleur
            // nog bevestigen (geofence is dan alleen de voorwaarde om in te dienen).
            $autoApprove = $objective->verification === 'auto';
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('exercise-submissions', 'public');
        }

        $participant = $mission->participants->firstWhere('user_id', Auth::id());

        $submission = ExerciseSubmission::create([
            'objective_id'   => $objective->id,
            'mission_id'     => $mission->id,
            'user_id'        => Auth::id(),
            'faction_id'     => $participant?->faction_id,
            'status'         => $autoApprove ? 'approved' : 'pending',
            'note'           => $data['note'] ?? null,
            'photo_path'     => $photoPath,
            'awarded_points' => $autoApprove ? $objective->points : null,
            'submitted_at'   => now(),
            'reviewed_at'    => $autoApprove ? now() : null,
        ]);

        return response()->json([
            'submission'   => $this->present($submission),
            'auto_approved' => $autoApprove,
        ], 201);
    }

    /**
     * Mijn eigen acties in deze oefening.
     * GET /api/v1/missions/{id}/exercise/submissions/mine
     */
    public function mine($id)
    {
        $mission = $this->participantMission($id);

        $subs = $mission->submissions()
            ->where('user_id', Auth::id())
            ->with('objective:id,title,points')
            ->latest('submitted_at')
            ->get()
            ->map(fn ($s) => $this->present($s, true));

        return response()->json(['submissions' => $subs]);
    }

    /**
     * De beoordelingswachtrij (controleur/commandant). Filter op status.
     * GET /api/v1/missions/{id}/exercise/submissions?status=pending
     */
    public function index(Request $request, $id)
    {
        $mission = $this->controllerMission($id);

        $status = $request->query('status', 'pending');
        $query = $mission->submissions()
            ->with(['objective:id,title,points,type', 'user:id,first_name,last_name,email', 'faction:id,name,color']);

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $subs = $query->latest('submitted_at')->get()->map(fn ($s) => $this->present($s, true, true));

        return response()->json(['submissions' => $subs]);
    }

    /**
     * Een actie goed- of afkeuren (controleur/commandant).
     * POST /api/v1/missions/{id}/exercise/submissions/{subId}/review
     */
    public function review(Request $request, $id, $subId)
    {
        $mission = $this->controllerMission($id);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'points'   => 'nullable|integer|min:0|max:100000',
        ]);

        $submission = $mission->submissions()->with('objective')->findOrFail($subId);

        if ($data['decision'] === 'approve') {
            $points = $data['points'] ?? $submission->objective?->points ?? 0;
            $submission->update([
                'status'         => 'approved',
                'awarded_points' => $points,
                'controller_id'  => Auth::id(),
                'reviewed_at'    => now(),
            ]);
        } else {
            $submission->update([
                'status'         => 'rejected',
                'awarded_points' => null,
                'controller_id'  => Auth::id(),
                'reviewed_at'    => now(),
            ]);
        }

        return response()->json(['submission' => $this->present($submission->fresh(['objective', 'user', 'faction']), true, true)]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Controleer of een ingediende locatie binnen de radius én het tijdvenster
     * van een locatie-doel valt. Retourneert ['ok'=>bool, 'code','message'].
     */
    protected function geofenceCheck(ExerciseObjective $o, $lat, $lng): array
    {
        if ($lat === null || $lng === null) {
            return ['ok' => false, 'code' => 'location_required', 'message' => 'Deel je locatie om dit doel te claimen.'];
        }

        // Tijdvenster.
        $now = now();
        if ($o->window_start && $now->lt($o->window_start)) {
            return ['ok' => false, 'code' => 'window_not_open', 'message' => 'Dit doel is nog niet open.'];
        }
        if ($o->window_end && $now->gt($o->window_end)) {
            return ['ok' => false, 'code' => 'window_closed', 'message' => 'Het tijdvenster voor dit doel is verstreken.'];
        }

        // Afstand tot het doel.
        if ($o->target_lat === null || $o->target_lng === null) {
            // Geen doelcoördinaat ingesteld → geen geofence mogelijk; laat door.
            return ['ok' => true, 'code' => null, 'message' => null];
        }

        $radius = $o->radius_m ?: 25; // standaard 25 m
        $dist = $this->haversine((float) $lat, (float) $lng, (float) $o->target_lat, (float) $o->target_lng);

        if ($dist > $radius) {
            return [
                'ok'      => false,
                'code'    => 'out_of_range',
                'message' => 'Je bent te ver van het doel (' . round($dist) . ' m, moet binnen ' . $radius . ' m).',
            ];
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    /** Afstand in meters tussen twee lat/lng-punten. */
    protected function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected function exerciseMission($id): Mission
    {
        $mission = Mission::with(['participants', 'exerciseSite'])->findOrFail($id);
        if (! $mission->isExercise()) {
            abort(404, 'Deze missie is geen oefening.');
        }
        return $mission;
    }

    protected function participantMission($id): Mission
    {
        $mission = $this->exerciseMission($id);
        if ($mission->exerciseRoleFor(Auth::id()) === null) {
            abort(403, 'Meld je eerst aan bij deze oefening.');
        }
        return $mission;
    }

    protected function controllerMission($id): Mission
    {
        $mission = $this->exerciseMission($id);
        if (! $mission->isExerciseController(Auth::id())) {
            abort(403, 'Alleen een controleur of commandant kan acties beoordelen.');
        }
        return $mission;
    }

    protected function present(ExerciseSubmission $s, bool $withObjective = false, bool $withActor = false): array
    {
        $arr = [
            'id'             => $s->id,
            'objective_id'   => $s->objective_id,
            'user_id'        => (int) $s->user_id,
            'faction_id'     => $s->faction_id,
            'status'         => $s->status,
            'note'           => $s->note,
            'photo_url'      => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
            'awarded_points' => $s->awarded_points,
            'submitted_at'   => $s->submitted_at?->toIso8601String(),
            'reviewed_at'    => $s->reviewed_at?->toIso8601String(),
        ];

        if ($withObjective && $s->relationLoaded('objective') && $s->objective) {
            $arr['objective'] = [
                'id'     => $s->objective->id,
                'title'  => $s->objective->title,
                'points' => $s->objective->points,
                'type'   => $s->objective->type ?? null,
            ];
        }

        if ($withActor) {
            $arr['user_name']    = $s->relationLoaded('user') ? ($s->user?->full_name ?? ('#' . $s->user_id)) : null;
            $arr['faction_name'] = $s->relationLoaded('faction') ? $s->faction?->name : null;
            $arr['faction_color'] = $s->relationLoaded('faction') ? $s->faction?->color : null;
        }

        return $arr;
    }
}
