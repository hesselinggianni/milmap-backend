<?php

use App\Models\Conversation;
use App\Models\Map;
use App\Models\Mission;
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
 * - Owner and accepted collaborators only.
 *
 * SECURITY: live GPS positions are sensitive. The previous implementation
 * returned true for ANY unauthenticated subscriber, so anyone who could guess a
 * map id streamed every sharer's live location. We now require authentication
 * plus map access (mirroring the `map.{mapId}` channel). A future public-share
 * viewer must validate a MapShare token explicitly rather than being waved
 * through unauthenticated.
 */
Broadcast::channel('map.{mapId}.locations', function ($user, $mapId) {
    if (! $user) {
        return false;
    }

    $map = Map::find($mapId);
    if (! $map) {
        return false;
    }

    // Owner has access.
    if ($map->owner_id === $user->id) {
        return true;
    }

    // Accepted collaborators have access.
    return $map->collaborators()
        ->where('user_id', $user->id)
        ->where('status', 'accepted')
        ->exists();
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

/**
 * Mission navigation channel
 * - All participants (owner + accepted collaborators + linked-team members when active)
 *   may subscribe to receive live participant positions.
 */
Broadcast::channel('mission.{missionId}', function ($user, $missionId) {
    if (! $user) {
        return false;
    }

    $mission = Mission::find($missionId);
    if (! $mission) {
        return false;
    }

    if (! $mission->hasAccess($user->id)) {
        return false;
    }

    return [
        'id'   => $user->id,
        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
    ];
});
