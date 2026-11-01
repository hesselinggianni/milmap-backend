<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionTeam;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Teams op een missie: de commandant koppelt meerdere teams, elk team werkt
 * zijn eigen versie van de uitvoering uit (zie Mission::teamScopeFor en
 * MissionLiveController) en heeft een eigen kaartlaag.
 *
 *   GET    /missions/{id}/teams            — wie wat ziet hangt af van je scope
 *   POST   /missions/{id}/teams            — team koppelen (eigenaar/admin)
 *   PUT    /missions/{id}/teams/{team}     — laag-delen instellen (eigenaar/admin)
 *   DELETE /missions/{id}/teams/{team}     — team loskoppelen (eigenaar/admin)
 *
 * {team} is het team-id (teams.id), niet het id van de koppelrij.
 */
class MissionTeamController extends Controller
{
    public function index($id)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();
        if (! $mission->hasAccess($userId)) {
            abort(403, 'You do not have access to this mission');
        }

        return response()->json($this->present($mission, $userId));
    }

    public function store(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();
        $this->authorizeManage($mission, $userId);

        $data = $request->validate(['team_id' => 'required|uuid|exists:teams,id']);

        // IDOR-guard, zoals bij linked_team_id: alleen een team dat je zelf beheert,
        // anders krijgen vreemden toegang tot deze missie.
        $team = Team::findOrFail($data['team_id']);
        if (! $team->isOwnedBy($userId)) {
            return response()->json(['error' => 'Je kunt alleen een eigen team aan een missie koppelen.'], 422);
        }

        $existing = $mission->missionTeams()->where('team_id', $team->id)->first();
        if (! $existing) {
            MissionTeam::create([
                'mission_id' => $mission->id,
                'team_id'    => $team->id,
                'sort_order' => (int) $mission->missionTeams()->max('sort_order') + 1,
            ]);
        }

        $mission->unsetRelation('missionTeams');

        return response()->json($this->present($mission, $userId), $existing ? 200 : 201);
    }

    public function update(Request $request, $id, $teamId)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();
        $this->authorizeManage($mission, $userId);

        $row = $mission->missionTeams()->where('team_id', $teamId)->firstOrFail();
        $data = $request->validate([
            'layer_share'   => 'sometimes|nullable|array|max:50',
            'layer_share.*' => 'uuid',
        ]);

        if (array_key_exists('layer_share', $data)) {
            // Alleen teams van deze missie, en nooit het team zelf.
            $allowed = array_diff($mission->teamIds(), [(string) $row->team_id]);
            $row->layer_share = array_values(array_intersect(array_map('strval', $data['layer_share'] ?? []), $allowed));
            $row->save();
        }

        $mission->unsetRelation('missionTeams');

        return response()->json($this->present($mission, $userId));
    }

    public function destroy($id, $teamId)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();
        $this->authorizeManage($mission, $userId);

        $mission->missionTeams()->where('team_id', $teamId)->delete();
        if ((string) $mission->linked_team_id === (string) $teamId) {
            $mission->linked_team_id = null;
            $mission->save();
        }
        // Het team dat verdwijnt hoeft zijn laag niet meer te delen, en anderen niet meer te zien.
        foreach ($mission->missionTeams()->get() as $other) {
            $share = array_values(array_diff($other->sharedWith(), [(string) $teamId]));
            if ($share !== $other->sharedWith()) {
                $other->layer_share = $share;
                $other->save();
            }
        }

        $mission->unsetRelation('missionTeams');

        return response()->json($this->present($mission, $userId));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function authorizeManage(Mission $mission, int $userId): void
    {
        if (! $mission->canManage($userId)) {
            abort(403, 'Only the mission owner or an admin can manage teams');
        }
        if (! Mission::teamsSupported()) {
            abort(503, 'Teams are not available yet');
        }
    }

    /**
     * De teams zoals deze gebruiker ze mag zien.
     *
     *  - commandant/editors: alle teams, met leden, laag-delen en voortgang;
     *  - teamleden: hun eigen team volledig + andere teams alleen als de laag
     *    met hen gedeeld is (naam, kleur en kaart — geen versie, geen leden).
     */
    private function present(Mission $mission, int $userId): array
    {
        $scope = $mission->teamScopeFor($userId);
        $canManage = $mission->canManage($userId);
        $blob = is_array($mission->ogroup) ? $mission->ogroup : [];
        $plans = is_array($blob['team_plans'] ?? null) ? $blob['team_plans'] : [];

        $rows = Mission::teamsSupported() ? $mission->missionTeams()->get()->keyBy(fn ($r) => (string) $r->team_id) : collect();
        // Het oude enkelvoudig gekoppelde team telt mee, ook zonder koppelrij.
        $teamIds = $mission->teamIds();
        $teams = Team::whereIn('id', $teamIds)->get()->keyBy(fn ($t) => (string) $t->id);
        $members = TeamMember::whereIn('team_id', $teamIds)->where('status', TeamMember::STATUS_ACTIVE)
            ->with('user:id,first_name,last_name,email,avatar_path')->get()->groupBy(fn ($m) => (string) $m->team_id);

        // Voor welke teams mag deze gebruiker de kaartlaag zien?
        $mineView = $scope['view'];
        $sharedToMe = [];
        foreach ($rows as $teamId => $row) {
            if (array_intersect($mineView, $row->sharedWith())) {
                $sharedToMe[] = (string) $teamId;
            }
        }

        $out = [];
        foreach ($teamIds as $teamId) {
            $team = $teams->get($teamId);
            if (! $team) {
                continue;
            }
            $row = $rows->get($teamId);
            $isMine = in_array($teamId, $scope['view'], true);
            $layerVisible = $scope['all'] || $isMine || in_array($teamId, $sharedToMe, true);
            if (! $scope['all'] && ! $isMine && ! $layerVisible) {
                continue;
            }

            $entry = [
                'team_id'      => $teamId,
                'name'         => $team->name,
                'color'        => $team->color,
                'map_id'       => $layerVisible ? $row?->map_id : null,
                'can_edit'     => $scope['all'] || in_array($teamId, $scope['edit'], true),
                'mine'         => $isMine,
                'legacy'       => ! $row,
                'layer_visible' => $layerVisible,
            ];

            if ($scope['all'] || $isMine) {
                $entry['members'] = ($members->get($teamId) ?? collect())->filter(fn ($m) => $m->user)->map(fn ($m) => [
                    'id'         => $m->user->id,
                    'name'       => $m->user->full_name,
                    'avatar_url' => $m->user->avatar_url,
                    'role'       => $m->role,
                ])->values();
                $entry['progress'] = $this->progress($plans[$teamId] ?? null);
            }
            if ($canManage) {
                $entry['layer_share'] = $row?->sharedWith() ?? [];
            }

            $out[] = $entry;
        }

        return [
            'data'       => $out,
            'can_manage' => $canManage,
            'my_team_ids' => $scope['view'],
            // Teams krijgen pas toegang zodra de missie actief (beschikbaar gesteld) is.
            'available'  => $mission->status === 'active',
        ];
    }

    /** Voortgang van een team op basis van de TLP-stappen in zijn versie (stap → status). */
    private function progress(?array $plan): array
    {
        $steps = is_array($plan['tlp']['steps'] ?? null) ? $plan['tlp']['steps'] : [];
        $statuses = [];
        foreach ($steps as $key => $step) {
            $statuses[$key] = is_array($step) ? (string) ($step['status'] ?? 'todo') : 'todo';
        }

        return ['steps' => $statuses];
    }
}
