<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Map;

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

        // Check if user is an accepted collaborator
        return $map->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists();
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
     */
    public function update(User $user, Map $map)
    {
        // Owner can update
        if ($this->isOwner($user, $map)) {
            return true;
        }

        // Check if user is an accepted collaborator
        return $map->collaborators()
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists();
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
}
