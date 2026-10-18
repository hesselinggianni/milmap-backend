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

        $handoff = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $entry = AuthHandoffToken::where('token', $data['token'])->lockForUpdate()->first();
            if (!$entry || !$entry->isValid()) return null;
            $entry->forceFill(['used_at' => now()])->save();
            return $entry;
        });
        if (!$handoff) return response()->json(['message' => 'Invalid or expired token.'], 422);

        $user = $handoff->user;
        if ($user->isBlocked()) {
            return response()->json(['message' => $user->blockedLoginMessage(), 'code' => 'account_blocked'], 403);
        }
        $newToken = $user->createToken('API Token (handoff)', ['user'], now()->addHours(1));
        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_ip' => $request->ip(),
        ])->save();

        $user->withAccessToken($newToken->accessToken);
        app(\App\Services\ConnectManagement::class)->forgetContext($user);
        return response()->json([
            'user' => array_merge($user->toArray(), [
                'connect_management' => app(\App\Services\ConnectManagement::class)->context($user),
                'verification' => $user->verificationState(),
                'premium' => $user->premiumState(),
            ]),
            'token' => $newToken->plainTextToken,
        ]);
    }
}
