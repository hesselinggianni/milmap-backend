<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\User;
use App\Models\MissionCollaborator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class MissionCollaboratorController extends Controller
{
    /**
     * List collaborators for a mission (owner or admin only).
     * GET /api/v1/missions/{missionId}/collaborators
     */
    public function index($missionId)
    {
        $mission = Mission::findOrFail($missionId);

        if (!$mission->canManage(Auth::id())) {
            abort(403, 'Only the owner or an admin can view collaborators');
        }

        $collaborators = $mission->collaborators()
            ->with(['user:id,first_name,last_name,email', 'inviter:id,first_name,last_name'])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'user_name' => $c->user?->full_name ?? $c->user?->email,
                'email' => $c->user?->email,
                'role' => $c->role ?? 'editor',
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
     * Invite a collaborator by user_id (instant) or email (pending), with a role.
     * POST /api/v1/missions/{missionId}/collaborators
     */
    public function store(Request $request, $missionId)
    {
        $mission = Mission::findOrFail($missionId);

        if (!$mission->canManage(Auth::id())) {
            abort(403, 'Only the owner or an admin can invite collaborators');
        }

        $request->validate([
            'email' => 'nullable|email|exists:users,email',
            'user_id' => 'nullable|integer|exists:users,id',
            'role' => 'nullable|in:viewer,editor,admin',
        ]);

        if (!$request->filled('email') && !$request->filled('user_id')) {
            return response()->json(['error' => 'Either email or user_id must be provided'], 422);
        }
        if ($request->filled('email') && $request->filled('user_id')) {
            return response()->json(['error' => 'Provide either email or user_id, not both'], 422);
        }

        // Resolve the invited user.
        $isEmailInvite = false;
        if ($request->filled('user_id')) {
            $user = User::findOrFail($request->user_id);
        } else {
            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return response()->json([
                    'error' => 'No user with this email. They must create an account first.',
                ], 422);
            }
            $isEmailInvite = true;
        }

        // Owner cannot be added as collaborator.
        if ($mission->isOwnedBy($user->id)) {
            return response()->json(['error' => 'The mission owner cannot be added as a collaborator'], 422);
        }

        // Already invited / collaborating?
        $existing = $mission->collaborators()->where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json([
                'error' => $existing->isAccepted()
                    ? 'This user is already a collaborator on this mission'
                    : 'An invitation is already pending for this user',
            ], 422);
        }

        $collaborator = MissionCollaborator::create([
            'mission_id' => $mission->id,
            'user_id' => $user->id,
            'added_by' => Auth::id(),
            'role' => $request->input('role', 'editor'),
            // Direct user picks accept immediately; email invites stay pending.
            'status' => $isEmailInvite ? 'pending' : 'accepted',
            'invitation_token' => $isEmailInvite ? Str::random(64) : null,
            'invited_at' => now(),
            'accepted_at' => $isEmailInvite ? null : now(),
        ]);

        return response()->json([
            'message' => $isEmailInvite
                ? 'Invitation sent to ' . $user->email
                : 'Collaborator added',
            'collaborator' => [
                'id' => $collaborator->id,
                'user_id' => $collaborator->user_id,
                'user_name' => $user->full_name,
                'email' => $user->email,
                'role' => $collaborator->role,
                'status' => $collaborator->status,
            ],
        ], 201);
    }

    /**
     * Change a collaborator's role (owner or admin only).
     * PUT /api/v1/missions/{missionId}/collaborators/{userId}
     */
    public function updateRole(Request $request, $missionId, $userId)
    {
        $mission = Mission::findOrFail($missionId);

        if (!$mission->canManage(Auth::id())) {
            abort(403, 'Only the owner or an admin can change roles');
        }

        $request->validate(['role' => 'required|in:viewer,editor,admin']);

        if ($mission->isOwnedBy((int) $userId)) {
            return response()->json(['error' => 'The owner role cannot be changed'], 422);
        }

        $collaborator = $mission->collaborators()->where('user_id', $userId)->firstOrFail();
        $collaborator->update(['role' => $request->role]);

        return response()->json([
            'message' => 'Role updated',
            'collaborator' => [
                'user_id' => $collaborator->user_id,
                'role' => $collaborator->role,
            ],
        ]);
    }

    /**
     * Remove a collaborator (owner or admin only).
     * DELETE /api/v1/missions/{missionId}/collaborators/{userId}
     */
    public function destroy($missionId, $userId)
    {
        $mission = Mission::findOrFail($missionId);

        if (!$mission->canManage(Auth::id())) {
            abort(403, 'Only the owner or an admin can remove collaborators');
        }

        if ($mission->isOwnedBy((int) $userId)) {
            return response()->json(['error' => 'Cannot remove the mission owner'], 422);
        }

        $mission->collaborators()->where('user_id', $userId)->firstOrFail()->delete();

        return response()->json(['message' => 'Collaborator removed']);
    }

    /**
     * List the authenticated user's pending mission invitations.
     * GET /api/v1/missions/invitations/pending
     */
    public function myInvitations()
    {
        $invites = MissionCollaborator::query()
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->with(['mission:id,name,owner_id,date', 'inviter:id,first_name,last_name'])
            ->latest('invited_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'mission_id' => $c->mission_id,
                'mission_name' => $c->mission?->name,
                'role' => $c->role,
                'invited_by' => $c->inviter?->full_name,
                'invited_at' => $c->invited_at,
            ]);

        return response()->json(['invitations' => $invites]);
    }

    /**
     * Accept a pending invitation addressed to the authenticated user.
     * POST /api/v1/missions/invitations/{id}/accept
     */
    public function accept($id)
    {
        $collaboration = MissionCollaborator::findOrFail($id);

        if ((int) $collaboration->user_id !== Auth::id()) {
            abort(403, 'This invitation is not addressed to you');
        }
        if ($collaboration->status !== 'pending') {
            return response()->json(['error' => 'This invitation is no longer valid'], 422);
        }

        $collaboration->accept();

        return response()->json([
            'message' => 'Invitation accepted',
            'mission_id' => $collaboration->mission_id,
        ]);
    }

    /**
     * Decline a pending invitation addressed to the authenticated user.
     * DELETE /api/v1/missions/invitations/{id}
     */
    public function decline($id)
    {
        $collaboration = MissionCollaborator::findOrFail($id);

        if ((int) $collaboration->user_id !== Auth::id()) {
            abort(403, 'This invitation is not addressed to you');
        }

        $collaboration->delete();

        return response()->json(['message' => 'Invitation declined']);
    }
}
