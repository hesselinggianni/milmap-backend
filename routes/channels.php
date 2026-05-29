<?php

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
