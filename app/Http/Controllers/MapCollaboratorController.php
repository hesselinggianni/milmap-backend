<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Mission;
use App\Models\User;
use App\Models\MapCollaborator;
use App\Models\Invitation;
use App\Mail\CollaborationInvite;
use App\Events\MapCollaboratorAdded;
use App\Events\MapCollaboratorRemoved;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class MapCollaboratorController extends Controller
{
    /**
     * Get all users with access to a map.
     * GET /api/v1/maps/{mapId}/collaborators
     *
     * Returns three categories:
     *   source='collaborator'  – directly added via map sharing
     *   source='invitation'    – pending e-mail invite
     *   source='mission'       – access via a mission linked to this map
     *
     * Non-owners get a read-only view (own entry only hidden; all others
     * visible so collaborators can see who else is on the map).
     */
    public function index($mapId)
    {
        $map = Map::findOrFail($mapId);

        $isOwner = (int) $map->owner_id === Auth::id();

        // ── Direct map collaborators ──────────────────────────────────────────
        $collaborators = $map->collaborators()
            ->with(['user:id,first_name,last_name,email'])
            ->get()
            ->map(fn($c) => [
                'id'          => $c->id,
                'user_id'     => $c->user_id,
                'user_name'   => $c->user?->full_name ?? $c->user?->email,
                'email'       => $isOwner ? ($c->user?->email) : null,
                'role'        => $c->role ?? 'editor',
                'status'      => $c->status,
                'source'      => 'collaborator',
                'invited_at'  => $c->invited_at,
                'accepted_at' => $c->accepted_at,
            ]);

        // ── Pending e-mail invitations (owner-only — invites contain e-mails) ─
        $pendingInvitations = collect();
        if ($isOwner) {
            $pendingInvitations = Invitation::where('invitable_type', Map::class)
                ->where('invitable_id', $mapId)
                ->where('status', 'pending')
                ->with(['invitedUser:id,first_name,last_name,email'])
                ->get()
                ->map(fn($i) => [
                    'id'          => $i->id,
                    'user_id'     => $i->invited_user_id,
                    'user_name'   => $i->invitedUser?->full_name ?? $i->email,
                    'email'       => $i->email,
                    'role'        => $i->role ?? 'viewer',
                    'status'      => 'pending',
                    'source'      => 'invitation',
                    'invited_at'  => $i->created_at,
                    'accepted_at' => null,
                ]);
        }

        // ── Mission participants (missions whose map.id points to this map) ───
        // missions.map is a JSON column: { id: "<uuid>", source: "server"|"local", title: "..." }
        $linkedMissions = Mission::whereNotNull('map')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`map`, '$.id')) = ?", [$mapId])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`map`, '$.source')) = 'server'")
            ->with([
                'collaborators' => fn($q) => $q->where('status', 'accepted')
                    ->with('user:id,first_name,last_name,email'),
                'owner:id,first_name,last_name,email',
            ])
            ->get();

        // Collect all unique mission participants keyed by user_id
        $missionUsers = collect();
        foreach ($linkedMissions as $mission) {
            // Mission owner
            if ($mission->owner) {
                $missionUsers[$mission->owner->id] = [
                    'user_id'       => $mission->owner->id,
                    'user_name'     => $mission->owner->full_name,
                    'email'         => $isOwner ? $mission->owner->email : null,
                    'role'          => 'admin',
                    'status'        => 'accepted',
                    'source'        => 'mission',
                    'mission_name'  => $mission->name,
                    'mission_id'    => $mission->id,
                ];
            }
            // Mission collaborators
            foreach ($mission->collaborators as $mc) {
                if (!$mc->user) continue;
                if (!isset($missionUsers[$mc->user_id])) {
                    $missionUsers[$mc->user_id] = [
                        'user_id'      => $mc->user_id,
                        'user_name'    => $mc->user->full_name ?? $mc->user->email,
                        'email'        => $isOwner ? $mc->user->email : null,
                        'role'         => $mc->role ?? 'viewer',
                        'status'       => 'accepted',
                        'source'       => 'mission',
                        'mission_name' => $mission->name,
                        'mission_id'   => $mission->id,
                    ];
                }
            }
        }

        // Remove the map owner from mission list (they're the owner, not a participant)
        $missionUsers->forget($map->owner_id);
        // Remove users already in the direct collaborators list
        $directIds = $collaborators->pluck('user_id')->all();
        $missionParticipants = $missionUsers->filter(
            fn($u) => !in_array($u['user_id'], $directIds)
        )->values();

        $all = $collaborators->concat($pendingInvitations)->concat($missionParticipants)->values();

        return response()->json([
            'collaborators' => $all,
            'total'         => $all->count(),
            'is_owner'      => $isOwner,
        ]);
    }

    /**
     * Add a collaborator (by email or user ID)
     * POST /api/v1/maps/{mapId}/collaborators
     *
     * Request body:
     * {
     *   "email": "user@example.com" OR "user_id": 123
     * }
     */
    public function store(Request $request, $mapId)
    {
        $map = Map::findOrFail($mapId);

        // Only owner can add collaborators
        if ((int) $map->owner_id !== Auth::id()) {
            abort(403, 'Only the map owner can add collaborators');
        }

        // Validate: either email or user_id, but not both
        $request->validate([
            'email' => 'nullable|email',
            'user_id' => 'nullable|integer|exists:users,id',
            'role' => 'nullable|in:viewer,editor,admin',
        ]);

        // At least one of email or user_id must be provided
        if (!$request->filled('email') && !$request->filled('user_id')) {
            return response()->json([
                'error' => 'Either email or user_id must be provided',
            ], 422);
        }

        // Cannot provide both
        if ($request->filled('email') && $request->filled('user_id')) {
            return response()->json([
                'error' => 'Provide either email or user_id, not both',
            ], 422);
        }

        // ── E-mail invitation (existing user OR brand-new recipient) ──
        // Routed through the polymorphic invitations table so it works even
        // when the e-mail does not belong to a MilMap user yet.
        if ($request->filled('email')) {
            $result = app(InvitationService::class)->createInvite(
                $map,
                $request->email,
                $request->input('role', 'viewer'),
                Auth::user()
            );

            if (!$result['ok']) {
                return response()->json(['error' => $result['error']], $result['status'] ?? 422);
            }

            $inv = $result['invitation'];

            return response()->json([
                'message' => $result['email_sent']
                    ? 'Uitnodiging verzonden naar ' . $inv->email
                    : 'Uitnodiging aangemaakt (e-mail kon niet worden verzonden).',
                'email_sent' => $result['email_sent'],
                'invitation' => [
                    'id' => $inv->id,
                    'email' => $inv->email,
                    'role' => $inv->role,
                    'status' => $inv->status,
                    'is_new_user' => $result['isNewUser'],
                ],
            ], 201);
        }

        // ── Direct add of a known contact (instant access) ──
        $user = User::findOrFail($request->user_id);

        // Cannot add owner as collaborator
        if ($user->id === (int) $map->owner_id) {
            return response()->json([
                'error' => 'The map owner cannot be added as a collaborator',
            ], 422);
        }

        // Check if already collaborator
        $existing = $map->collaborators()
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => $existing->isAccepted()
                    ? 'This user is already a collaborator on this map'
                    : 'An invitation is already pending for this user',
            ], 422);
        }

        // Create collaboration record (immediate access)
        $collaborator = MapCollaborator::create([
            'map_id' => $map->id,
            'user_id' => $user->id,
            'added_by' => Auth::id(),
            'role' => $request->input('role', 'editor'),
            'status' => 'accepted',
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        broadcast(new MapCollaboratorAdded($collaborator))->toOthers();

        return response()->json([
            'message' => 'Collaborator added successfully',
            'collaborator' => [
                'id' => $collaborator->id,
                'user_id' => $collaborator->user_id,
                'role' => $collaborator->role,
                'status' => $collaborator->status,
                'user_name' => $user->full_name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * Update a collaborator's role
     * PUT /api/v1/maps/{mapId}/collaborators/{userId}
     *
     * Request body: { "role": "viewer|editor|admin" }
     */
    public function updateRole(Request $request, $mapId, $userId)
    {
        $map = Map::findOrFail($mapId);

        // Only owner can change roles
        if ((int) $map->owner_id !== Auth::id()) {
            abort(403, 'Only the map owner can change collaborator roles');
        }

        $request->validate([
            'role' => 'required|in:viewer,editor,admin',
        ]);

        // Cannot change the owner's role
        if ((int) $userId === (int) $map->owner_id) {
            return response()->json([
                'error' => 'The map owner role cannot be changed',
            ], 422);
        }

        $collaborator = $map->collaborators()
            ->where('user_id', $userId)
            ->firstOrFail();

        $collaborator->update(['role' => $request->role]);

        return response()->json([
            'message' => 'Role updated successfully',
            'collaborator' => [
                'user_id' => $collaborator->user_id,
                'role' => $collaborator->role,
            ],
        ]);
    }

    /**
     * Remove a collaborator from a map
     * DELETE /api/v1/maps/{mapId}/collaborators/{userId}
     */
    public function destroy($mapId, $userId)
    {
        $map = Map::findOrFail($mapId);

        // Only owner can remove collaborators
        if ((int) $map->owner_id !== Auth::id()) {
            abort(403, 'Only the map owner can remove collaborators');
        }

        // Cannot remove owner
        if ((int) $userId === (int) $map->owner_id) {
            return response()->json([
                'error' => 'Cannot remove the map owner',
            ], 422);
        }

        $collaborator = $map->collaborators()
            ->where('user_id', $userId)
            ->firstOrFail();

        $collaborator->delete();

        // Broadcast event to notify all viewers
        broadcast(new MapCollaboratorRemoved($map, $userId))->toOthers();

        return response()->json([
            'message' => 'Collaborator removed successfully',
        ]);
    }

    /**
     * Accept an email invitation
     * POST /api/v1/invitations/{token}/accept
     */
    public function acceptInvitation(Request $request, $token)
    {
        $collaboration = MapCollaborator::where('invitation_token', $token)->firstOrFail();

        // Verify the invitation is pending
        if ($collaboration->status !== 'pending') {
            return response()->json([
                'error' => 'This invitation is no longer valid',
            ], 422);
        }

        // SECURITY: this endpoint was previously public — anyone holding or
        // guessing a token could flip a pending collaboration to accepted with
        // no addressee check. It now runs behind auth:sanctum and we verify the
        // authenticated user is the exact account the invitation was issued to.
        $userId = Auth::id();
        if (! $userId) {
            return response()->json([
                'error' => 'You must be logged in to accept this invitation.',
            ], 401);
        }
        if ((int) $collaboration->user_id !== (int) $userId) {
            return response()->json([
                'error' => 'This invitation is not addressed to your account.',
            ], 403);
        }

        // Accept the invitation
        $collaboration->accept();

        return response()->json([
            'message' => 'Invitation accepted! You now have access to the map.',
            'map_id' => $collaboration->map_id,
            'map_title' => $collaboration->map->title,
        ]);
    }

    /**
     * Search for users to add as collaborators.
     *
     * Privacy: only returns people the current user has *already collaborated
     * with* (an accepted collaboration on any mission or map). Strangers are
     * never disclosed — to add someone new you invite them by e-mail instead.
     *
     * GET /api/v1/users/search?q={query}
     */
    public function searchUsers(Request $request)
    {
        $query = trim($request->query('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $contactIds = InvitationService::knownContactIds(Auth::id());

        if (empty($contactIds)) {
            return response()->json(['users' => []]);
        }

        $like = "%{$query}%";

        $users = User::whereIn('id', $contactIds)
            ->where(function ($q) use ($like) {
                $q->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            })
            ->select('id', 'first_name', 'last_name', 'avatar_path', 'email')
            ->limit(20)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'email' => $u->email,
                'avatar_url' => $u->avatar_url,
            ]);

        return response()->json(['users' => $users]);
    }
}
