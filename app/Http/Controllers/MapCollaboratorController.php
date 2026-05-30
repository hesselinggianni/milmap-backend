<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\User;
use App\Models\MapCollaborator;
use App\Mail\CollaborationInvite;
use App\Events\MapCollaboratorAdded;
use App\Events\MapCollaboratorRemoved;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class MapCollaboratorController extends Controller
{
    /**
     * Get all collaborators for a map
     * GET /api/v1/maps/{mapId}/collaborators
     */
    public function index($mapId)
    {
        $map = Map::findOrFail($mapId);

        // Only owner can view collaborators
        if ((int) $map->owner_id !== Auth::id()) {
            abort(403, 'Only the map owner can view collaborators');
        }

        $collaborators = $map->collaborators()
            ->with(['user:id,first_name,last_name,email', 'inviter:id,first_name,last_name'])
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'user_name' => $c->user?->full_name ?? $c->user?->email,
                'email' => $c->user?->email,
                'status' => $c->status,
                'invited_at' => $c->invited_at,
                'accepted_at' => $c->accepted_at,
            ]);

        return response()->json([
            'collaborators' => $collaborators,
            'total' => count($collaborators),
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
            'email' => 'nullable|email|exists:users,email',
            'user_id' => 'nullable|integer|exists:users,id',
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

        // Determine user and invitation type
        $user = null;
        $invitationToken = null;
        $isEmailInvite = false;

        if ($request->filled('user_id')) {
            // Direct user selection (immediate acceptance)
            $user = User::findOrFail($request->user_id);
        } else {
            // Email invitation (pending until user accepts)
            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return response()->json([
                    'error' => 'User with this email does not exist. User must create an account first.',
                ], 422);
            }
            $invitationToken = Str::random(64);
            $isEmailInvite = true;
        }

        // Check if already collaborator
        $existing = $map->collaborators()
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->isAccepted()) {
                return response()->json([
                    'error' => 'This user is already a collaborator on this map',
                ], 422);
            } else {
                return response()->json([
                    'error' => 'An invitation is already pending for this user',
                ], 422);
            }
        }

        // Cannot add owner as collaborator
        if ($user->id === (int) $map->owner_id) {
            return response()->json([
                'error' => 'The map owner cannot be added as a collaborator',
            ], 422);
        }

        // Create collaboration record
        $collaborator = MapCollaborator::create([
            'map_id' => $map->id,
            'user_id' => $user->id,
            'added_by' => Auth::id(),
            'status' => $isEmailInvite ? 'pending' : 'accepted',
            'invitation_token' => $invitationToken,
            'invited_at' => now(),
            'accepted_at' => $isEmailInvite ? null : now(),
        ]);

        // Broadcast event to all map viewers (only for immediate collaborators)
        if (!$isEmailInvite) {
            broadcast(new MapCollaboratorAdded($collaborator))->toOthers();
        }

        // Send invitation email if email-based invite
        if ($isEmailInvite) {
            Mail::queue(new CollaborationInvite(
                user: $user,
                map: $map,
                inviter: Auth::user(),
                invitationToken: $invitationToken,
            ));
        }

        return response()->json([
            'message' => $invitationToken ? 'Invitation sent to ' . $user->email : 'Collaborator added successfully',
            'collaborator' => [
                'id' => $collaborator->id,
                'user_id' => $collaborator->user_id,
                'status' => $collaborator->status,
                'user_name' => $user->full_name,
                'email' => $user->email,
            ],
        ], 201);
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

        // Accept the invitation
        $collaboration->accept();

        return response()->json([
            'message' => 'Invitation accepted! You now have access to the map.',
            'map_id' => $collaboration->map_id,
            'map_title' => $collaboration->map->title,
        ]);
    }

    /**
     * Search for users to add as collaborators
     * GET /api/v1/users/search?q={query}
     */
    public function searchUsers(Request $request)
    {
        $query = $request->query('q', '');

        if (strlen($query) < 2) {
            return response()->json([
                'users' => [],
            ]);
        }

        $users = User::where('email', 'like', "%{$query}%")
            ->orWhere('first_name', 'like', "%{$query}%")
            ->orWhere('last_name', 'like', "%{$query}%")
            ->select('id', 'first_name', 'last_name', 'email')
            ->limit(20)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'email' => $u->email,
            ]);

        return response()->json([
            'users' => $users,
        ]);
    }
}
