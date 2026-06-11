<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MissionController extends Controller
{
    /**
     * List all missions the user can see: ones they own plus ones they were
     * invited to and accepted.
     * GET /api/v1/missions
     */
    public function index()
    {
        $userId = Auth::id();

        // Team IDs the user belongs to (for auto-viewer access)
        $teamIds = TeamMember::where('user_id', $userId)->pluck('team_id');

        $missions = Mission::query()
            // Eager-load what present()/roleFor() need so the list isn't N+1
            // (one collaborators + one linked-team query per mission otherwise).
            ->with(['collaborators', 'linkedTeam' => fn ($q) => $q->withCount('members')])
            ->where('owner_id', $userId)
            ->orWhereHas('collaborators', function ($q) use ($userId) {
                $q->where('user_id', $userId)->where('status', 'accepted');
            })
            ->orWhere(function ($q) use ($teamIds) {
                // Auto-viewer: active missions whose linked team includes the user
                $q->where('status', 'active')
                  ->whereIn('linked_team_id', $teamIds);
            })
            ->latest('updated_at')
            ->get();

        return response()->json([
            // Logo data-URLs are omitted from the list to keep the payload lean;
            // they are only included when a single mission is opened.
            'data' => $missions->map(fn ($m) => $this->present($m, $userId, false)),
        ]);
    }

    /**
     * Single mission (owner or accepted collaborator).
     * GET /api/v1/missions/{id}
     */
    public function show($id)
    {
        $mission = Mission::findOrFail($id);
        $userId = Auth::id();

        if (!$mission->hasAccess($userId)) {
            abort(403, 'You do not have access to this mission');
        }

        return response()->json(['data' => $this->present($mission, $userId)]);
    }

    /**
     * Create a mission. The creator becomes the owner.
     * POST /api/v1/missions
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:planning,active,complete',
            'date' => 'nullable|date',
            'time' => 'nullable|string',
            'ogroup' => 'nullable|array',
            'map' => 'nullable|array',
            'logo' => 'nullable|string|max:5000000',
        ]);

        $mission = Mission::create([
            'owner_id' => Auth::id(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'planning',
            'date' => $data['date'] ?? null,
            'time' => $data['time'] ?? null,
            'ogroup' => $data['ogroup'] ?? null,
            'map' => $data['map'] ?? null,
            'logo' => $data['logo'] ?? null,
        ]);

        return response()->json(['data' => $this->present($mission, Auth::id())], 201);
    }

    /**
     * Update a mission (owner, editor or admin).
     * PUT /api/v1/missions/{id}
     */
    public function update(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $userId = Auth::id();

        if (!$mission->canEdit($userId)) {
            abort(403, 'You do not have permission to edit this mission');
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:planning,active,complete',
            'date' => 'nullable|date',
            'time' => 'nullable|string',
            'ogroup' => 'nullable|array',
            'map' => 'nullable|array',
            'logo' => 'nullable|string|max:5000000',
            'linked_team_id' => 'nullable|uuid|exists:teams,id',
        ]);

        // Linking / unlinking a team is a management action (owner or admin only)
        if (array_key_exists('linked_team_id', $data) && !$mission->canManage($userId)) {
            abort(403, 'Only the mission owner or admin can link a team');
        }

        // IDOR guard: a `exists:teams,id` rule only proves the team exists, not
        // that it's the actor's. Linking an arbitrary team would silently grant
        // that team's members viewer access to this mission and expose the
        // mission to them — so only a team the actor OWNS may be linked.
        if (!empty($data['linked_team_id'])) {
            $team = \App\Models\Team::find($data['linked_team_id']);
            if (!$team || !$team->isOwnedBy($userId)) {
                return response()->json([
                    'error' => 'Je kunt alleen een eigen team aan een missie koppelen.',
                ], 422);
            }
        }

        // Detecteer status-overgang voor de melding naar deelnemers. We
        // pakken de oude waarde vóór ->fill() — daarna is hij overschreven.
        $oldStatus = $mission->status;
        $newStatus = $data['status'] ?? $oldStatus;

        $mission->fill($data);
        $mission->save();

        // Status veranderd → real-time melding op het mission presence channel.
        // Wordt niet gepersisteerd (clients tonen een toast en updaten de chip).
        if ($newStatus !== $oldStatus) {
            try {
                $actor = Auth::user();
                $name  = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''))
                    ?: ($actor->name ?? $actor->email ?? 'Iemand');

                broadcast(new \App\Events\MissionStatusChanged(
                    (string) $mission->id,
                    (string) ($mission->name ?? 'Missie'),
                    (string) $oldStatus,
                    (string) $newStatus,
                    (int) $userId,
                    $name,
                    now()->toIso8601String(),
                ))->toOthers();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[mission] status broadcast failed: ' . $e->getMessage());
            }
        }

        return response()->json(['data' => $this->present($mission->fresh(), $userId)]);
    }

    /**
     * Delete a mission (owner only).
     * DELETE /api/v1/missions/{id}
     */
    public function destroy($id)
    {
        $mission = Mission::findOrFail($id);

        if (!$mission->isOwnedBy(Auth::id())) {
            abort(403, 'Only the mission owner can delete it');
        }

        $mission->delete();

        return response()->json(['message' => 'Mission deleted']);
    }

    /**
     * Shape a mission for the frontend, including the viewer's role + rights.
     */
    private function present(Mission $mission, int $userId, bool $includeLogo = true): array
    {
        $role = $mission->roleFor($userId);

        // Linked team summary (id, name, color, member count). Use the
        // eager-loaded relation when present (mission list) to avoid a per-row
        // query; fall back to a direct lookup for the single-mission path.
        $linkedTeam = null;
        if ($mission->linked_team_id) {
            $team = $mission->relationLoaded('linkedTeam')
                ? $mission->linkedTeam
                : $mission->linkedTeam()->withCount('members')->first();
            if ($team) {
                $linkedTeam = [
                    'id'            => $team->id,
                    'name'          => $team->name,
                    'color'         => $team->color,
                    'members_count' => $team->members_count ?? $team->members()->count(),
                ];
            }
        }

        $out = [
            'id' => $mission->id,
            'owner_id' => $mission->owner_id,
            'linked_team_id' => $mission->linked_team_id,
            'linked_team' => $linkedTeam,
            'name' => $mission->name,
            'description' => $mission->description,
            'status' => $mission->status,
            'date' => $mission->date?->toDateString(),
            'time' => $mission->time,
            'ogroup' => $mission->ogroup,
            'map' => $mission->map,
            'created_at' => $mission->created_at,
            'updated_at' => $mission->updated_at,
            // Access info for UI gating
            'my_role' => $role,
            'is_owner' => $role === 'owner',
            'can_edit' => in_array($role, ['owner', 'editor', 'admin'], true),
            'can_manage' => in_array($role, ['owner', 'admin'], true),
        ];

        if ($includeLogo) {
            $out['logo'] = $mission->logo;
        }

        return $out;
    }
}
