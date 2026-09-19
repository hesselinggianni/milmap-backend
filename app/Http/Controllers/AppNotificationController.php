<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * In-app meldingen voor de Hub-timeline en /meldingen.
 *
 * Voorbeeld type: "mission.completed" — gegenereerd door MissionController
 * wanneer een missie op afgerond wordt gezet (deelnemers krijgen een rij
 * "vul de debrief in" met een action_url naar de juiste tab).
 *
 * Twee feeds, één controller. Meldingen met een `admin.`-type (nieuwe mail in
 * een inbox, supportticket, feature-verzoek) horen in de admin-omgeving en niet
 * in de gewone app: de beheerder is daar dezelfde gebruiker, dus zonder filter
 * ziet die zijn inbox-meldingen tussen zijn missiemeldingen staan. De route
 * bepaalt welke kant je krijgt — /admin/notifications alleen admin-meldingen,
 * /me/notifications alleen de rest.
 */
class AppNotificationController extends Controller
{
    /** Draait dit verzoek in de admin-omgeving? */
    private function isAdminContext(Request $request): bool
    {
        return $request->is('api/v1/admin/*');
    }

    /** Meldingen van de ingelogde gebruiker, gefilterd op de juiste feed. */
    private function scoped(Request $request): Builder
    {
        $query = AppNotification::where('user_id', Auth::id());

        return $this->isAdminContext($request)
            ? $query->where('type', 'like', 'admin.%')
            : $query->where(function ($q) {
                // NOT LIKE laat NULL-types vallen; die horen in de app-feed.
                $q->whereNull('type')->orWhere('type', 'not like', 'admin.%');
            });
    }

    /** GET /api/v1/me/notifications — en /api/v1/admin/notifications */
    public function index(Request $request)
    {
        $per = (int) $request->query('per', 50);

        $notifications = $this->scoped($request)
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

        // Teller telt dezelfde feed, anders klopt de badge niet met de lijst.
        $unread = $this->scoped($request)->whereNull('read_at')->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);
    }

    /** POST /api/v1/me/notifications/{id}/read */
    public function markRead(Request $request, int $id)
    {
        $n = $this->scoped($request)->findOrFail($id);
        $n->read_at = now();
        $n->save();
        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/me/notifications/read-all */
    public function markAllRead(Request $request)
    {
        // Alleen de zichtbare feed: "alles gelezen" in de app mag de
        // inbox-meldingen in de admin-omgeving niet wegstrepen.
        $this->scoped($request)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** DELETE /api/v1/me/notifications/{id} */
    public function destroy(Request $request, int $id)
    {
        $this->scoped($request)->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
