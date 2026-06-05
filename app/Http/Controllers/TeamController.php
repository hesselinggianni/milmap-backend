<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\Mission;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function __construct(protected InvitationService $service)
    {
    }

    /**
     * Teams owned by the authenticated user (with their members).
     * GET /api/v1/teams
     */
    public function index()
    {
        $teams = Team::where('owner_id', Auth::id())
            ->with(['members.user:id,first_name,last_name,email'])
            ->latest()
            ->get()
            ->map(fn ($t) => $this->present($t));

        return response()->json(['teams' => $teams]);
    }

    /**
     * Create a new team.
     * POST /api/v1/teams
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'color'       => 'nullable|string|max:16',
        ]);

        $team = Team::create([
            'owner_id'    => Auth::id(),
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'color'       => $data['color'] ?? null,
        ]);

        $team->load(['members.user:id,first_name,last_name,email']);

        return response()->json(['team' => $this->present($team)], 201);
    }

    /**
     * A single team (owner only).
     * GET /api/v1/teams/{team}
     */
    public function show($id)
    {
        $team = $this->ownedTeam($id);

        return response()->json(['team' => $this->present($team)]);
    }

    /**
     * Update a team's name / description / color.
     * PUT /api/v1/teams/{team}
     */
    public function update(Request $request, $id)
    {
        $team = $this->ownedTeam($id);

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'color'       => 'nullable|string|max:16',
        ]);

        $team->update($data);
        $team->load(['members.user:id,first_name,last_name,email']);

        return response()->json(['team' => $this->present($team)]);
    }

    /**
     * Delete a team (does not touch any invitations already sent out).
     * DELETE /api/v1/teams/{team}
     */
    public function destroy($id)
    {
        $team = $this->ownedTeam($id);
        $team->delete();

        return response()->json(['message' => 'Team verwijderd.']);
    }

    /**
     * Add a member to a team, by user_id (a known contact) or by raw e-mail
     * (someone who may not have a MilMap account yet).
     * POST /api/v1/teams/{team}/members
     */
    public function addMember(Request $request, $id)
    {
        $team = $this->ownedTeam($id);

        $request->validate([
            'email'   => 'nullable|email',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $email = null;
        $userId = null;

        if ($request->filled('user_id')) {
            $user   = User::findOrFail($request->user_id);
            $email  = mb_strtolower($user->email);
            $userId = $user->id;
        } elseif ($request->filled('email')) {
            $email = mb_strtolower(trim($request->email));
            $userId = User::where('email', $email)->value('id');
        } else {
            return response()->json(['error' => 'Geef een e-mailadres of gebruiker op.'], 422);
        }

        if (TeamMember::where('team_id', $team->id)->where('email', $email)->exists()) {
            return response()->json(['error' => 'Dit teamlid staat al in het team.'], 422);
        }

        $member = TeamMember::create([
            'team_id'  => $team->id,
            'email'    => $email,
            'user_id'  => $userId,
            'added_by' => Auth::id(),
        ]);

        $member->load('user:id,first_name,last_name,email');

        return response()->json(['member' => $this->presentMember($member)], 201);
    }

    /**
     * Remove a member from a team.
     * DELETE /api/v1/teams/{team}/members/{member}
     */
    public function removeMember($id, $memberId)
    {
        $team   = $this->ownedTeam($id);
        $member = TeamMember::where('team_id', $team->id)->findOrFail($memberId);
        $member->delete();

        return response()->json(['message' => 'Teamlid verwijderd.']);
    }

    /**
     * Invite an entire team to a mission or map: fan out one e-mail invitation
     * per team member through the shared InvitationService.
     * POST /api/v1/teams/{team}/invite
     */
    public function invite(Request $request, $id)
    {
        $team = $this->ownedTeam($id);

        $data = $request->validate([
            'invitable_type' => ['required', Rule::in(['mission', 'map'])],
            'invitable_id'   => 'required|string',
            'role'           => ['nullable', Rule::in(['viewer', 'editor', 'admin'])],
        ]);

        $resource = $data['invitable_type'] === 'mission'
            ? Mission::find($data['invitable_id'])
            : Map::find($data['invitable_id']);

        if (! $resource) {
            return response()->json(['error' => 'De missie of kaart bestaat niet.'], 404);
        }

        if (! $this->canManageResource($resource, Auth::id())) {
            return response()->json(['error' => 'Je hebt geen rechten om hier mensen voor uit te nodigen.'], 403);
        }

        $role    = $data['role'] ?? 'viewer';
        $inviter = Auth::user();

        $invited = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($team->members as $member) {
            $result = $this->service->createInvite($resource, $member->email, $role, $inviter);
            if (! empty($result['ok'])) {
                $invited++;
                if (($result['email_sent'] ?? true) === false) {
                    $failed++;
                }
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'message' => "Team \"{$team->name}\" uitgenodigd: {$invited} uitgenodigd, {$skipped} overgeslagen.",
            'invited' => $invited,
            'skipped' => $skipped,
            'email_failed' => $failed,
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    protected function ownedTeam($id): Team
    {
        $team = Team::with(['members.user:id,first_name,last_name,email'])->findOrFail($id);

        if (! $team->isOwnedBy(Auth::id())) {
            abort(403, 'Dit is niet jouw team.');
        }

        return $team;
    }

    protected function canManageResource($resource, int $userId): bool
    {
        if ($resource instanceof Mission) {
            return $resource->canManage($userId);
        }

        // Map: owner or accepted admin collaborator.
        if ((int) $resource->owner_id === $userId) {
            return true;
        }

        return MapCollaborator::where('map_id', $resource->id)
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->where('role', 'admin')
            ->exists();
    }

    protected function present(Team $team): array
    {
        return [
            'id'            => $team->id,
            'name'          => $team->name,
            'description'   => $team->description,
            'color'         => $team->color,
            'members_count' => $team->members->count(),
            'members'       => $team->members->map(fn ($m) => $this->presentMember($m))->values(),
            'created_at'    => $team->created_at?->toIso8601String(),
        ];
    }

    protected function presentMember(TeamMember $member): array
    {
        return [
            'id'      => $member->id,
            'email'   => $member->email,
            'user_id' => $member->user_id,
            'name'    => $member->user?->full_name ?? $member->email,
        ];
    }
}
