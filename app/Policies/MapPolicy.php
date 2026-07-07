<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Map;
use App\Models\Mission;

class MapPolicy
{
    /**
     * Determine if the user can view any maps.
     */
    public function viewAny(User $user)
    {
        // All authenticated users can view their own and collaborated maps
        return true;
    }

    /**
     * Determine if the user can view a specific map.
     */
    public function view(User $user, Map $map)
    {
        // Owner can always view
        if ($this->isOwner($user, $map)) {
            return true;
        }

        // Direct map collaborator (accepted invite)
        if ($map->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists()) {
            return true;
        }

        // Mission collaborator: if a mission that links this map exists and the
        // user has (at minimum) viewer access to that mission, grant map access.
        return $this->hasMissionAccess($user, $map);
    }

    /**
     * Determine if the user can create maps.
     */
    public function create(User $user)
    {
        // Any authenticated user can create maps
        return true;
    }

    /**
     * Determine if the user can update the map.
     * Editor/admin mission collaborators also get write access.
     */
    public function update(User $user, Map $map)
    {
        if ($this->isOwner($user, $map)) {
            return true;
        }

        // Direct map collaborator
        if ($map->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists()) {
            return true;
        }

        // Mission collaborator with edit role
        return $this->hasMissionAccess($user, $map, requireEdit: true);
    }

    /**
     * Determine if the user can delete the map.
     */
    public function delete(User $user, Map $map)
    {
        // Only owner can delete
        return $this->isOwner($user, $map);
    }

    /**
     * Determine if the user can add collaborators to the map.
     */
    public function addCollaborator(User $user, Map $map)
    {
        // Only owner can add collaborators
        return $this->isOwner($user, $map);
    }

    /**
     * Determine if the user can remove collaborators from the map.
     */
    public function removeCollaborator(User $user, Map $map)
    {
        // Only owner can remove collaborators
        return $this->isOwner($user, $map);
    }

    /**
     * Helper method to check if user has edit access to the map.
     */
    public function edit(User $user, Map $map)
    {
        return $this->update($user, $map);
    }

    /**
     * Whether the given user owns the map.
     *
     * owner_id is stored in a uuid (string) column while User ids are
     * auto-increment integers, so a strict === would fail ("2" === 2).
     */
    protected function isOwner(User $user, Map $map): bool
    {
        return (string) $map->owner_id === (string) $user->id;
    }

    /**
     * Resolve the user's effective role on this map.
     * Returns 'owner' | 'editor' | 'viewer' | null (no access).
     * 'editor' covers both direct map collaborators and mission editor/admin/owner.
     * 'viewer' covers mission viewer collaborators.
     */
    public function roleFor(User $user, Map $map): ?string
    {
        if ($this->isOwner($user, $map)) return 'owner';

        if ($map->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists()) {
            return 'editor';
        }

        // Check mission-based access
        $missionRole = $this->missionRoleFor($user, $map);
        if ($missionRole === null) return null;
        return in_array($missionRole, ['owner', 'editor', 'admin'], true) ? 'editor' : 'viewer';
    }

    /**
     * Check whether the user has access to this map via a linked mission.
     * When requireEdit is true, only editor/admin/owner mission roles count.
     */
    protected function hasMissionAccess(User $user, Map $map, bool $requireEdit = false): bool
    {
        $role = $this->missionRoleFor($user, $map);
        if ($role === null) return false;
        if ($requireEdit) return in_array($role, ['owner', 'editor', 'admin'], true);
        return true;
    }

    /**
     * Return the user's role on any mission that links to this map, or null.
     * Uses JSON path query on the missions.map column (stored as {"id": "...", ...}).
     */
    protected function missionRoleFor(User $user, Map $map): ?string
    {
        $mission = Mission::where('map->id', (string) $map->id)
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                  ->orWhereHas('collaborators', fn ($c) =>
                      $c->where('user_id', $user->id)->where('status', 'accepted')
                  );
            })
            ->first();

        if (!$mission) return null;
        return $mission->roleFor($user->id);
    }
}
