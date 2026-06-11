<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Mission;
use App\Models\RouteMap;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Prullenbak — soft-deleted Maps / Missions / RouteMaps plus per-user
 * archived chats. Items auto-purge after PURGE_DAYS (see PurgeTrash command).
 *
 * Authorization model:
 *   - Maps:      owner only
 *   - Missions:  owner only (matches MissionController::destroy)
 *   - RouteMaps: owner of the parent Map  (deletion gate is broader, but only
 *                the map owner should see/restore from the trash so a
 *                collaborator can't undo the owner's cleanup)
 *   - Chats:     the user themselves — chat "delete" is per-participant
 *                (archived_at on conversation_user), never global
 */
class TrashController extends Controller
{
    public const PURGE_DAYS = 60;

    /**
     * GET /api/v1/trash — every type the caller can see, with days_left.
     */
    public function index(Request $request)
    {
        $uid = Auth::id();

        return response()->json([
            'purge_days' => self::PURGE_DAYS,
            'maps'       => $this->mapsForUser($uid),
            'missions'   => $this->missionsForUser($uid),
            'route_maps' => $this->routeMapsForUser($uid),
            'chats'      => $this->chatsForUser($uid),
        ]);
    }

    /**
     * POST /api/v1/trash/{type}/{id}/restore
     */
    public function restore(string $type, string $id)
    {
        $uid = Auth::id();

        return match ($type) {
            'maps'       => $this->restoreMap($uid, $id),
            'missions'   => $this->restoreMission($uid, $id),
            'route_maps' => $this->restoreRouteMap($uid, $id),
            'chats'      => $this->restoreChat($uid, $id),
            default      => response()->json(['message' => 'Onbekend type'], 422),
        };
    }

    /**
     * DELETE /api/v1/trash/{type}/{id} — definitief verwijderen.
     */
    public function forceDelete(string $type, string $id)
    {
        $uid = Auth::id();

        return match ($type) {
            'maps'       => $this->forceDeleteMap($uid, $id),
            'missions'   => $this->forceDeleteMission($uid, $id),
            'route_maps' => $this->forceDeleteRouteMap($uid, $id),
            'chats'      => $this->forceDeleteChat($uid, $id),
            default      => response()->json(['message' => 'Onbekend type'], 422),
        };
    }

    // ── Listings ──────────────────────────────────────────────

    protected function mapsForUser(int $uid): array
    {
        return Map::onlyTrashed()
            ->where('owner_id', $uid)
            ->orderByDesc('deleted_at')
            ->get(['id', 'title', 'deleted_at'])
            ->map(fn ($m) => [
                'id'         => $m->id,
                'title'      => $m->title,
                'deleted_at' => optional($m->deleted_at)->toIso8601String(),
                'days_left'  => $this->daysLeft($m->deleted_at),
            ])->all();
    }

    protected function missionsForUser(int $uid): array
    {
        return Mission::onlyTrashed()
            ->where('owner_id', $uid)
            ->orderByDesc('deleted_at')
            ->get(['id', 'name', 'deleted_at'])
            ->map(fn ($m) => [
                'id'         => $m->id,
                'title'      => $m->name,
                'deleted_at' => optional($m->deleted_at)->toIso8601String(),
                'days_left'  => $this->daysLeft($m->deleted_at),
            ])->all();
    }

    protected function routeMapsForUser(int $uid): array
    {
        // Only the parent-map owner sees the route-map in their trash. Without
        // this gate a collaborator could pop another user's route into THEIR
        // trash and restore it from there, which surprises everyone.
        return RouteMap::onlyTrashed()
            ->whereHas('map', fn ($q) => $q->where('owner_id', $uid))
            ->orderByDesc('deleted_at')
            ->get(['id', 'title', 'deleted_at', 'map_id'])
            ->map(fn ($r) => [
                'id'         => $r->id,
                'title'      => $r->title,
                'map_id'     => $r->map_id,
                'deleted_at' => optional($r->deleted_at)->toIso8601String(),
                'days_left'  => $this->daysLeft($r->deleted_at),
            ])->all();
    }

    /**
     * Per-user archived chats. We read the pivot directly because we want the
     * archived_at timestamp, not the conversation's updated_at.
     */
    protected function chatsForUser(int $uid): array
    {
        $rows = DB::table('conversation_user as cu')
            ->join('conversations as c', 'c.id', '=', 'cu.conversation_id')
            ->where('cu.user_id', $uid)
            ->whereNotNull('cu.archived_at')
            ->orderByDesc('cu.archived_at')
            ->get(['c.id', 'c.type', 'c.title', 'cu.archived_at']);

        return $rows->map(fn ($r) => [
            'id'         => $r->id,
            'title'      => $this->chatTitleFor($r, $uid),
            'type'       => $r->type,
            'deleted_at' => Carbon::parse($r->archived_at)->toIso8601String(),
            'days_left'  => $this->daysLeft(Carbon::parse($r->archived_at)),
        ])->all();
    }

    /**
     * For direct chats the stored title is empty — show the other participant.
     */
    protected function chatTitleFor(object $row, int $uid): string
    {
        if (! empty($row->title)) {
            return $row->title;
        }
        $other = DB::table('conversation_user as cu')
            ->join('users as u', 'u.id', '=', 'cu.user_id')
            ->where('cu.conversation_id', $row->id)
            ->where('cu.user_id', '!=', $uid)
            ->value(DB::raw("COALESCE(u.name, CONCAT(u.first_name,' ',u.last_name), u.email)"));
        return $other ?: 'Gesprek';
    }

    // ── Restore ───────────────────────────────────────────────

    protected function restoreMap(int $uid, string $id)
    {
        $map = Map::onlyTrashed()->where('owner_id', $uid)->find($id);
        if (! $map) return response()->json(['message' => 'Niet gevonden'], 404);
        $map->restore();
        return response()->json(['message' => 'Hersteld']);
    }

    protected function restoreMission(int $uid, string $id)
    {
        $m = Mission::onlyTrashed()->where('owner_id', $uid)->find($id);
        if (! $m) return response()->json(['message' => 'Niet gevonden'], 404);
        $m->restore();
        return response()->json(['message' => 'Hersteld']);
    }

    protected function restoreRouteMap(int $uid, string $id)
    {
        $r = RouteMap::onlyTrashed()
            ->whereHas('map', fn ($q) => $q->where('owner_id', $uid))
            ->find($id);
        if (! $r) return response()->json(['message' => 'Niet gevonden'], 404);
        $r->restore();
        return response()->json(['message' => 'Hersteld']);
    }

    protected function restoreChat(int $uid, string $id)
    {
        $updated = DB::table('conversation_user')
            ->where('conversation_id', $id)
            ->where('user_id', $uid)
            ->whereNotNull('archived_at')
            ->update(['archived_at' => null]);
        if (! $updated) return response()->json(['message' => 'Niet gevonden'], 404);
        return response()->json(['message' => 'Hersteld']);
    }

    // ── Force delete ──────────────────────────────────────────

    protected function forceDeleteMap(int $uid, string $id)
    {
        $map = Map::onlyTrashed()->where('owner_id', $uid)->find($id);
        if (! $map) return response()->json(['message' => 'Niet gevonden'], 404);
        $map->forceDelete();
        return response()->json(['message' => 'Definitief verwijderd']);
    }

    protected function forceDeleteMission(int $uid, string $id)
    {
        $m = Mission::onlyTrashed()->where('owner_id', $uid)->find($id);
        if (! $m) return response()->json(['message' => 'Niet gevonden'], 404);
        $m->forceDelete();
        return response()->json(['message' => 'Definitief verwijderd']);
    }

    protected function forceDeleteRouteMap(int $uid, string $id)
    {
        $r = RouteMap::onlyTrashed()
            ->whereHas('map', fn ($q) => $q->where('owner_id', $uid))
            ->find($id);
        if (! $r) return response()->json(['message' => 'Niet gevonden'], 404);
        $r->forceDelete();
        return response()->json(['message' => 'Definitief verwijderd']);
    }

    /**
     * For chats, "definitief verwijderen" means: drop the pivot row, so this
     * user has fully left the conversation. The conversation itself stays alive
     * for the other participants (or becomes orphaned and is cleaned by the
     * existing conversation pruning, if any).
     */
    protected function forceDeleteChat(int $uid, string $id)
    {
        $deleted = DB::table('conversation_user')
            ->where('conversation_id', $id)
            ->where('user_id', $uid)
            ->whereNotNull('archived_at')
            ->delete();
        if (! $deleted) return response()->json(['message' => 'Niet gevonden'], 404);

        // If no participants remain, drop the conversation itself so we don't
        // accumulate orphan rows. Anything dependent (messages, etc.) is
        // cascaded by the schema's FK constraints.
        $remaining = DB::table('conversation_user')->where('conversation_id', $id)->count();
        if ($remaining === 0) {
            Conversation::where('id', $id)->delete();
        }

        return response()->json(['message' => 'Definitief verwijderd']);
    }

    // ── Helpers ───────────────────────────────────────────────

    protected function daysLeft(?Carbon $deletedAt): int
    {
        if (! $deletedAt) return 0;
        $left = self::PURGE_DAYS - $deletedAt->diffInDays(now());
        return (int) max(0, $left);
    }
}
