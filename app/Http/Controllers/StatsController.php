<?php

namespace App\Http\Controllers;

use App\Models\ChatRequest;
use App\Models\Conversation;
use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\Mission;
use App\Models\MissionCollaborator;
use App\Models\RouteMap;
use App\Models\TeamMember;
use App\Models\UserUpload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Per-user "App data" stats for the menu/account page.
 *
 *  - Counts: maps, missions, route_maps, chats, teams (owned + member-of),
 *    team-members (total across owned teams), contacts (accepted chat reqs +
 *    accepted collaborators).
 *
 *  - Storage: per-user verbruik uit twee bronnen, opgeteld in `total_bytes`.
 *    Routekaart-foto's: each route_map owns its own checkpoint folder under
 *    `storage/app/public/routemaps/<id>/`; we sum those bytes for route_maps
 *    the user owns. Chat-bijlagen zijn E2EE-verzegeld en hebben geen owner-
 *    fingerprint op het bericht, dus die kunnen we niet terug-queryen — daarom
 *    legt elke server-side upload een lichte metadata-regel vast in
 *    `user_uploads` (zie UserUpload) die we hier per `kind` sommeren.
 */
class StatsController extends Controller
{
    public function show()
    {
        $uid = Auth::id();

        $counts = [
            'maps'        => Map::where('owner_id', $uid)->count(),
            'missions'    => Mission::where('owner_id', $uid)->count(),
            'route_maps'  => RouteMap::whereHas('map', fn ($q) => $q->where('owner_id', $uid))->count(),
            'chats'       => Conversation::whereHas('participants', fn ($q) =>
                                $q->where('users.id', $uid)
                                  ->whereNull('conversation_user.archived_at'))->count(),
        ];

        // Teams — owned by me + teams I'm a member of (deduped).
        $ownedTeamIds = DB::table('teams')->where('owner_id', $uid)->pluck('id');
        $memberTeamIds = TeamMember::where('user_id', $uid)->pluck('team_id');
        $allMyTeamIds = $ownedTeamIds->concat($memberTeamIds)->unique()->values();

        $teams = [
            'owned'           => $ownedTeamIds->count(),
            'member_of'       => $allMyTeamIds->count(),
            // Total members across teams I OWN (the ones I'm responsible for).
            'members_in_owned' => TeamMember::whereIn('team_id', $ownedTeamIds)->count(),
        ];

        // Contacts = accepted chat requests (either direction) + accepted
        // collaborators on my own content. Same person can show up via more
        // than one source, so we dedupe by user_id at the very end.
        $contactIds = collect();

        $contactIds = $contactIds->merge(
            ChatRequest::where('status', 'accepted')
                ->where(fn ($q) => $q->where('requester_id', $uid)->orWhere('recipient_id', $uid))
                ->get(['requester_id', 'recipient_id'])
                ->flatMap(fn ($r) => [$r->requester_id, $r->recipient_id])
        );

        $contactIds = $contactIds->merge(
            MapCollaborator::whereHas('map', fn ($q) => $q->where('owner_id', $uid))
                ->whereNotNull('user_id')
                ->pluck('user_id')
        );

        $contactIds = $contactIds->merge(
            MissionCollaborator::whereHas('mission', fn ($q) => $q->where('owner_id', $uid))
                ->whereNotNull('user_id')
                ->pluck('user_id')
        );

        $contacts = $contactIds->filter(fn ($id) => $id && $id !== $uid)->unique()->count();

        // Storage — routekaart-foto's. Walk the owned route_maps' folders on
        // the public disk and sum file sizes. Cheap on a typical user (<1k
        // files) and cached for a minute below if we ever need it.
        $bytesRouteMapImages = 0;
        $ownedRouteMapIds = RouteMap::whereHas('map', fn ($q) => $q->where('owner_id', $uid))
            ->pluck('id');

        $disk = Storage::disk('public');
        foreach ($ownedRouteMapIds as $rid) {
            $prefix = 'routemaps/' . $rid;
            if (! $disk->exists($prefix)) continue;
            foreach ($disk->allFiles($prefix) as $rel) {
                try { $bytesRouteMapImages += (int) $disk->size($rel); }
                catch (\Throwable $e) { /* skip transient */ }
            }
        }

        // Chat-bijlagen: sinds het opslag-grootboek (user_uploads) legt elke
        // server-side upload een lichte metadata-regel vast. We sommeren de
        // bytes per gebruiker — geen folderwalk, geen ontsleuteling nodig.
        $bytesChat = (int) UserUpload::where('user_id', $uid)
            ->where('kind', 'chat')
            ->sum('size');

        // Overige geledgerde uploads (bijv. mission_logo) die NIET al via de
        // routemap-folderwalk hierboven geteld zijn. 'routemap' sluiten we uit
        // om dubbeltelling te voorkomen.
        $bytesOtherUploads = (int) UserUpload::where('user_id', $uid)
            ->whereNotIn('kind', ['chat', 'routemap'])
            ->sum('size');

        $totalBytes = $bytesRouteMapImages + $bytesChat + $bytesOtherUploads;

        $storage = [
            'route_map_images_bytes' => $bytesRouteMapImages,
            'chat_attachments_bytes' => $bytesChat,
            'other_uploads_bytes'    => $bytesOtherUploads,
            // Eén getal dat de gebruiker als "totaal verbruik" kan tonen.
            'total_bytes'            => $totalBytes,
        ];

        return response()->json([
            'counts'   => $counts,
            'teams'    => $teams,
            'contacts' => $contacts,
            'storage'  => $storage,
        ]);
    }
}
