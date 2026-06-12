<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * In-app meldingen voor de Hub-timeline en /meldingen.
 *
 * Voorbeeld type: "mission.completed" — gegenereerd door MissionController
 * wanneer een missie op afgerond wordt gezet (deelnemers krijgen een rij
 * "vul de debrief in" met een action_url naar de juiste tab).
 */
class AppNotificationController extends Controller
{
    /** GET /api/v1/me/notifications */
    public function index(Request $request)
    {
        $uid = Auth::id();
        $per = (int) $request->query('per', 50);

        $notifications = AppNotification::where('user_id', $uid)
            ->orderByDesc('id')
            ->limit(min($per, 200))
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'title'      => $n->title,
                'body'       => $n->body,
                'payload'    => $n->payload,
                'read_at'    => optional($n->read_at)->toIso8601String(),
                'created_at' => optional($n->created_at)->toIso8601String(),
            ]);

        $unread = AppNotification::where('user_id', $uid)->whereNull('read_at')->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);
    }

    /** POST /api/v1/me/notifications/{id}/read */
    public function markRead(int $id)
    {
        $uid = Auth::id();
        $n = AppNotification::where('user_id', $uid)->findOrFail($id);
        $n->read_at = now();
        $n->save();
        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/me/notifications/read-all */
    public function markAllRead()
    {
        $uid = Auth::id();
        AppNotification::where('user_id', $uid)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** DELETE /api/v1/me/notifications/{id} */
    public function destroy(int $id)
    {
        $uid = Auth::id();
        AppNotification::where('user_id', $uid)->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
