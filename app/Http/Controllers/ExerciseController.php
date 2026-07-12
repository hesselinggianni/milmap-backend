<?php

namespace App\Http\Controllers;

use App\Models\ExerciseFaction;
use App\Models\ExerciseParticipant;
use App\Models\ExerciseSite;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Oefening-modus, mission-scoped: een missie tot oefening maken, partijen
 * (facties) beheren, en de deelname-flow (partijkeuze → regels-akkoord → actief
 * meedoen). Rollen: commander (beheert), controller (keurt acties), player.
 *
 * Zie docs/oefening-modus.md. Doelen + acties + scores zitten in
 * ExerciseObjectiveController / ExerciseSubmissionController / ExerciseScoreController.
 */
class ExerciseController extends Controller
{
    /**
     * Een missie tot oefening maken: game_mode='exercise', koppel een terrein en
     * (optioneel) seed direct de partijen. Alleen de missie-eigenaar/commandant.
     * POST /api/v1/missions/{id}/exercise/setup
     */
    public function setup(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);

        if (! $mission->isOwnedBy(Auth::id()) && ! $mission->canManage(Auth::id())) {
            abort(403, 'Alleen de missie-eigenaar kan de oefening-modus instellen.');
        }

        $data = $request->validate([
            'exercise_site_id'  => 'nullable|string|exists:exercise_sites,id',
            'factions'          => 'nullable|array|max:20',
            'factions.*.name'   => 'required_with:factions|string|max:100',
            'factions.*.color'  => 'nullable|string|max:16',
        ]);

        $mission->update([
            'game_mode'        => 'exercise',
            'exercise_site_id' => $data['exercise_site_id'] ?? $mission->exercise_site_id,
        ]);

        if (! empty($data['factions'])) {
            foreach ($data['factions'] as $f) {
                ExerciseFaction::create([
                    'mission_id' => $mission->id,
                    'name'       => $f['name'],
                    'color'      => $f['color'] ?? null,
                ]);
            }
        }

        return response()->json(['state' => $this->buildState($mission->fresh(), Auth::id())]);
    }

    /**
     * De volledige oefening-toestand voor het huidige lid: terrein + huisregels,
     * mijn rol, mijn deelname, de partijen en tellingen. Eén bootstrap-call voor
     * de oefening-view.
     * GET /api/v1/missions/{id}/exercise/state
     */
    public function state($id)
    {
        $mission = $this->exerciseMission($id);

        return response()->json(['state' => $this->buildState($mission, Auth::id())]);
    }

    /**
     * Wie doet mee: alle deelnemers met partij, rol en actief-status.
     * GET /api/v1/missions/{id}/exercise/roster
     */
    public function roster($id)
    {
        $mission = $this->exerciseMission($id);

        $participants = $mission->participants()
            ->with('user:id,first_name,last_name,email')
            ->get()
            ->map(fn ($p) => $this->presentParticipant($mission, $p));

        return response()->json(['participants' => $participants]);
    }

    // ── Partijen (facties) ────────────────────────────────────────────

    public function listFactions($id)
    {
        $mission = $this->exerciseMission($id);

        return response()->json(['factions' => $mission->factions()->get()->map(fn ($f) => $this->presentFaction($f))]);
    }

    public function storeFaction(Request $request, $id)
    {
        $mission = $this->commanderMission($id);

        $data = $this->validateFaction($request);

        $faction = ExerciseFaction::create(array_merge($data, ['mission_id' => $mission->id]));

        return response()->json(['faction' => $this->presentFaction($faction)], 201);
    }

    public function updateFaction(Request $request, $id, $factionId)
    {
        $mission = $this->commanderMission($id);

        $faction = $mission->factions()->findOrFail($factionId);
        $faction->update($this->validateFaction($request, true));

        return response()->json(['faction' => $this->presentFaction($faction)]);
    }

    public function destroyFaction($id, $factionId)
    {
        $mission = $this->commanderMission($id);

        $mission->factions()->findOrFail($factionId)->delete();

        return response()->json(['message' => 'Partij verwijderd.']);
    }

    // ── Deelname-flow ─────────────────────────────────────────────────

    /**
     * Deelnemen aan de oefening en (optioneel) een partij kiezen. Zelfbediening:
     * elke ingelogde gebruiker mag zich als deelnemer bij een oefening aanmelden.
     * POST /api/v1/missions/{id}/exercise/join
     */
    public function join(Request $request, $id)
    {
        $mission = $this->exerciseMission($id);

        $data = $request->validate([
            'faction_id' => 'nullable|string',
        ]);

        $factionId = $this->resolveFactionId($mission, $data['faction_id'] ?? null);

        $participant = ExerciseParticipant::firstOrNew([
            'mission_id' => $mission->id,
            'user_id'    => Auth::id(),
        ]);

        if (! $participant->exists) {
            $participant->joined_at = now();
        }
        $participant->faction_id = $factionId;
        $participant->save();

        return response()->json(['state' => $this->buildState($mission, Auth::id())]);
    }

    /**
     * Akkoord met de huidige huisregels-versie van het terrein. Zonder terrein
     * (geen regels) is dit een no-op die meteen slaagt.
     * POST /api/v1/missions/{id}/exercise/accept-rules
     */
    public function acceptRules($id)
    {
        $mission = $this->exerciseMission($id);
        $participant = $this->requireParticipant($mission);

        $participant->update([
            'accepted_rules_version' => $this->siteRulesVersion($mission),
            'accepted_at'            => now(),
        ]);

        return response()->json(['state' => $this->buildState($mission, Auth::id())]);
    }

    /**
     * Actief meedoen aan/uit (locatie delen). Vereist dat de deelnemer akkoord is
     * met de huidige huisregels én een partij heeft gekozen.
     * POST /api/v1/missions/{id}/exercise/active
     */
    public function setActive(Request $request, $id)
    {
        $mission = $this->exerciseMission($id);
        $participant = $this->requireParticipant($mission);

        $data = $request->validate(['active' => 'required|boolean']);

        if ($data['active']) {
            if (! $this->rulesAccepted($mission, $participant)) {
                return response()->json([
                    'message' => 'Ga eerst akkoord met de terrein-regels om actief mee te doen.',
                    'code'    => 'rules_not_accepted',
                ], 422);
            }
            if (! $participant->faction_id) {
                return response()->json([
                    'message' => 'Kies eerst een partij om actief mee te doen.',
                    'code'    => 'no_faction',
                ], 422);
            }
        }

        $participant->update(['status' => $data['active'] ? 'active' : 'inactive']);

        return response()->json(['state' => $this->buildState($mission, Auth::id())]);
    }

    /**
     * Rol van een deelnemer overschrijven voor déze oefening (commander).
     * POST /api/v1/missions/{id}/exercise/participants/{userId}/role
     */
    public function setParticipantRole(Request $request, $id, $userId)
    {
        $mission = $this->commanderMission($id);

        $data = $request->validate([
            'role' => ['required', Rule::in(['commander', 'controller', 'player'])],
        ]);

        $participant = ExerciseParticipant::firstOrNew([
            'mission_id' => $mission->id,
            'user_id'    => $userId,
        ]);
        if (! $participant->exists) {
            $participant->joined_at = now();
        }
        $participant->role_override = $data['role'];
        $participant->save();

        $participant->load('user:id,first_name,last_name,email');

        return response()->json(['participant' => $this->presentParticipant($mission, $participant)]);
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

    protected function commanderMission($id): Mission
    {
        $mission = $this->exerciseMission($id);

        if (! $mission->isExerciseCommander(Auth::id())) {
            abort(403, 'Alleen een commandant kan dit beheren.');
        }

        return $mission;
    }

    protected function requireParticipant(Mission $mission): ExerciseParticipant
    {
        $participant = $mission->participants()->where('user_id', Auth::id())->first();

        if (! $participant) {
            abort(403, 'Je neemt niet deel aan deze oefening. Meld je eerst aan.');
        }

        return $participant;
    }

    protected function siteRulesVersion(Mission $mission): ?int
    {
        $site = $mission->exerciseSite;

        return $site?->rules_version;
    }

    protected function rulesAccepted(Mission $mission, ExerciseParticipant $participant): bool
    {
        $required = $this->siteRulesVersion($mission);

        // Geen terrein/regels → niets om akkoord op te geven.
        if ($required === null) {
            return true;
        }

        return (int) $participant->accepted_rules_version >= (int) $required;
    }

    protected function resolveFactionId(Mission $mission, ?string $factionId): ?string
    {
        if (! $factionId) {
            return null;
        }

        $exists = $mission->factions()->whereKey($factionId)->exists();
        if (! $exists) {
            abort(422, 'Onbekende partij voor deze oefening.');
        }

        return $factionId;
    }

    protected function validateFaction(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'      => "$rule|string|max:100",
            'color'     => 'nullable|string|max:16',
            'spawn_lat' => 'nullable|numeric|between:-90,90',
            'spawn_lng' => 'nullable|numeric|between:-180,180',
        ]);
    }

    protected function buildState(Mission $mission, int $uid): array
    {
        // Force-reload the collections that mutate within a request (participants,
        // factions) so state built right after a write reflects it; the site
        // rarely changes mid-request so loadMissing suffices there.
        $mission->load(['participants', 'factions']);
        $mission->loadMissing('exerciseSite');

        $me = $mission->participants->firstWhere('user_id', $uid);
        $site = $mission->exerciseSite;

        return [
            'mission_id'   => $mission->id,
            'game_mode'    => $mission->game_mode,
            'my_role'      => $mission->exerciseRoleFor($uid),
            'site'         => $site ? [
                'id'            => $site->id,
                'name'          => $site->name,
                'map_id'        => $site->map_id,
                'house_rules'   => $site->house_rules,
                'rules_version' => $site->rules_version,
            ] : null,
            'my_participation' => $me ? [
                'faction_id'             => $me->faction_id,
                'accepted_rules_version' => $me->accepted_rules_version,
                'rules_accepted'         => $this->rulesAccepted($mission, $me),
                'status'                 => $me->status,
            ] : null,
            'factions' => $mission->factions->map(function ($f) use ($mission) {
                $arr = $this->presentFaction($f);
                $arr['member_count'] = $mission->participants->where('faction_id', $f->id)->count();
                return $arr;
            })->values(),
            'counts' => [
                'participants' => $mission->participants->count(),
                'active'       => $mission->participants->where('status', 'active')->count(),
            ],
        ];
    }

    protected function presentFaction(ExerciseFaction $f): array
    {
        return [
            'id'        => $f->id,
            'name'      => $f->name,
            'color'     => $f->color,
            'spawn_lat' => $f->spawn_lat,
            'spawn_lng' => $f->spawn_lng,
        ];
    }

    protected function presentParticipant(Mission $mission, ExerciseParticipant $p): array
    {
        return [
            'id'             => $p->id,
            'user_id'        => (int) $p->user_id,
            'name'           => $p->user?->full_name ?? ('#' . $p->user_id),
            'faction_id'     => $p->faction_id,
            'role'           => $mission->exerciseRoleFor((int) $p->user_id),
            'role_override'  => $p->role_override,
            'status'         => $p->status,
            'rules_accepted' => $this->rulesAccepted($mission, $p),
            'joined_at'      => $p->joined_at?->toIso8601String(),
        ];
    }
}
