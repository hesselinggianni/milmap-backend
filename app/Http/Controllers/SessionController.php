<?php

namespace App\Http\Controllers;

use App\Support\DeviceInfo;
use Illuminate\Http\Request;

/**
 * Login-historie / actieve sessies. Eén Sanctum-token === één sessie/apparaat:
 * elke login mint een token (met device-metadata, zie LoginController), dus de
 * tokens van de gebruiker zijn precies de plekken waar hij is ingelogd.
 *
 * De gebruiker kan vanuit Account → Beveiliging:
 *   • zien vanaf welke client (webapp/iOS/Android/desktop) en welk apparaat,
 *     vanaf welk IP, wanneer ingelogd en wanneer laatst actief;
 *   • een specifieke sessie intrekken (uitloggen op dat apparaat);
 *   • alle andere sessies in één keer intrekken (uitloggen overal behalve hier).
 */
class SessionController extends Controller
{
    public function index(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $sessions = $request->user()->tokens()
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get()
            ->map(function ($t) use ($currentId) {
                return [
                    'id'           => $t->id,
                    'platform'     => $t->platform ?: DeviceInfo::platformFromUa($t->user_agent),
                    'device'       => DeviceInfo::device($t->user_agent),
                    'ip'           => $t->last_used_ip ?: $t->ip_address,
                    'created_at'   => optional($t->created_at)->toIso8601String(),
                    'last_used_at' => optional($t->last_used_at)->toIso8601String(),
                    'current'      => $t->id === $currentId,
                ];
            })
            ->values();

        return response()->json([
            'current_id' => $currentId,
            'sessions'   => $sessions,
        ]);
    }

    /**
     * Trek één sessie in. Het mag een willekeurige sessie van de gebruiker zijn,
     * ook de huidige — dan logt het huidige apparaat zichzelf uit.
     */
    public function destroy(Request $request, $id)
    {
        $token = $request->user()->tokens()->whereKey($id)->first();

        if (! $token) {
            return response()->json(['message' => 'Sessie niet gevonden.'], 404);
        }

        $token->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Log overal uit behalve op het huidige apparaat. Handig bij een verloren
     * of verdacht toestel: één klik en alle andere sessies zijn weg.
     */
    public function destroyOthers(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $request->user()->tokens()
            ->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
