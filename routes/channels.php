<?php

use App\Models\Conversation;
use App\Models\Map;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Map collaboration channel
 * - Allows map owner and accepted collaborators to receive real-time updates
 * - Examples: collaborator.added, collaborator.removed, routemap.updated, location.updated
 */
Broadcast::channel('map.{mapId}', function ($user, $mapId) {
    if (!$user) {
        return false;
    }

    $map = Map::find($mapId);
    if (!$map) {
        return false;
    }

    // Owner has access
    if ($map->owner_id === $user->id) {
        return true;
    }

    // Check if user is an accepted collaborator
    return $map->collaborators()
        ->where('user_id', $user->id)
        ->where('status', 'accepted')
        ->exists();
});

/**
 * Live user location sharing channel
 * - Authenticated users: must be collaborator on the map
 * - Public shares: allowed without authentication
 */
Broadcast::channel('map.{mapId}.locations', function ($user, $mapId) {
    // Find the map
    $map = Map::find($mapId);

    if (!$map) {
        return false;
    }

    // If user is authenticated, check if they have access to the map
    if ($user) {
        return $map->users->contains($user->id);
    }

    // If user is not authenticated, allow access (for public share links)
    // This allows guests viewing public share links to see live locations
    return true;
});

/**
 * Private chat conversation channel
 * - Only participants of the conversation may subscribe
 * - Carries the `message.created` E2EE event (server sees ciphertext only)
 */
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    if (! $user) {
        return false;
    }

    $conversation = Conversation::find($conversationId);
    if (! $conversation) {
        return false;
    }

    return $conversation->hasParticipant($user->id);
});
