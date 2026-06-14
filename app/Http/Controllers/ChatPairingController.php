<?php

namespace App\Http\Controllers;

use App\Models\ChatPairingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * QR-apparaatkoppeling voor de E2EE chat (WhatsApp-Web-patroon).
 *
 * Flow:
 *  1. create()  — het vergrendelde apparaat (web) maakt een sessie aan met zijn
 *                 EPHEMERAL publieke sleutel en toont {id, epk} als QR.
 *  2. claim()   — een reeds ontgrendeld apparaat (app) scant de QR, sealt de
 *                 chat-privésleutel naar de ephemeral key en levert de sealed
 *                 ciphertext hier af.
 *  3. show()    — het web pollt; zodra geclaimd krijgt het de sealed payload
 *                 terug (en de rij wordt direct verwijderd — eenmalig).
 *
 * De server ziet uitsluitend ciphertext: zonder de ephemeral PRIVÉ-sleutel kan
 * niets worden ontsleuteld. Alles is gebonden aan één account (user_id).
 */
class ChatPairingController extends Controller
{
    /** Levensduur van een koppelsessie. */
    private const TTL_SECONDS = 120;

    /**
     * Maak een nieuwe koppelsessie voor het tonende (vergrendelde) apparaat.
     */
    public function create(Request $request)
    {
        $data = $request->validate([
            'ephemeral_public_key' => ['required', 'string', 'max:255'],
        ]);

        // Verwijder en passant verlopen sessies van deze gebruiker.
        ChatPairingSession::where('user_id', Auth::id())
            ->where('expires_at', '<', now())
            ->delete();

        $session = ChatPairingSession::create([
            'id'                   => Str::random(40),
            'user_id'              => Auth::id(),
            'ephemeral_public_key' => $data['ephemeral_public_key'],
            'expires_at'           => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return response()->json([
            'id'         => $session->id,
            'expires_at' => $session->expires_at->toIso8601String(),
        ], 201);
    }

    /**
     * Het scannende (ontgrendelde) apparaat levert de sealed privésleutel af.
     * Alleen de eigenaar van de sessie mag claimen.
     */
    public function claim(Request $request, string $id)
    {
        $data = $request->validate([
            'sealed' => ['required', 'string', 'max:20000'],
        ]);

        $session = ChatPairingSession::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $session || $session->isExpired()) {
            return response()->json(['message' => 'Koppelsessie niet gevonden of verlopen.'], 410);
        }
        if ($session->claimed_at !== null) {
            return response()->json(['message' => 'Koppelsessie is al gebruikt.'], 409);
        }

        $session->sealed_payload = $data['sealed'];
        $session->claimed_at = now();
        $session->save();

        return response()->json(['status' => 'claimed']);
    }

    /**
     * Het tonende apparaat pollt de status. Levert de sealed payload eenmalig
     * en verwijdert daarna de sessie.
     */
    public function show(string $id)
    {
        $session = ChatPairingSession::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $session || $session->isExpired()) {
            if ($session) {
                $session->delete();
            }
            return response()->json(['status' => 'expired'], 410);
        }

        if ($session->sealed_payload === null) {
            return response()->json(['status' => 'pending']);
        }

        $sealed = $session->sealed_payload;
        // Eenmalig: na aflevering bestaat de sessie niet meer.
        $session->delete();

        return response()->json([
            'status' => 'claimed',
            'sealed' => $sealed,
        ]);
    }
}
