<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthHandoffToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// Draagt de ingelogde app-sessie over naar de systeembrowser (externe
// checkout, zie externalBilling.js): de webview deelt geen cookies/
// localStorage met Safari/Chrome, dus "open URL" logt daar normaal niemand
// in. create() (binnen de app, al ingelogd) mint een kortlevend eenmalig
// token; exchange() (publiek, in de externe browser) wisselt dat in voor een
// gewoon login-token — precies zoals LoginController::store() dat zou doen.
class HandoffController extends Controller
{
    public function create(Request $request)
    {
        // Alleen bedoeld voor de korte sprong naar de checkout-pagina — 2
        // minuten is ruim genoeg om de externe browser te laten openen.
        $token = AuthHandoffToken::create([
            'user_id' => $request->user()->id,
            'token' => Str::random(48),
            'expires_at' => now()->addMinutes(2),
        ]);

        return response()->json(['token' => $token->token]);
    }

    public function exchange(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
        ]);

        // Zwaar gethrottled op IP: dit endpoint levert bij een geldig token een
        // volwaardige login op, dus verdient dezelfde bescherming als de
        // reguliere inlog tegen het afgrazen van tokens.
        $ipKey = 'handoff-exchange:ip:' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }
        RateLimiter::hit($ipKey, 300);

        $handoff = AuthHandoffToken::where('token', $data['token'])->first();
        if (!$handoff || !$handoff->isValid()) {
            return response()->json(['message' => 'Invalid or expired token.'], 422);
        }

        // Eenmalig: meteen als gebruikt markeren, vóór het token uitgeven —
        // een dubbele inwisseling (race/replay) mag nooit twee sessies opleveren.
        $handoff->forceFill(['used_at' => now()])->save();

        $user = $handoff->user;
        $newToken = $user->createToken('API Token (handoff)', ['user']);
        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_ip' => $request->ip(),
        ])->save();

        return response()->json([
            'user' => array_merge($user->toArray(), [
                'verification' => $user->verificationState(),
                'premium' => $user->premiumState(),
            ]),
            'token' => $newToken->plainTextToken,
        ]);
    }
}
