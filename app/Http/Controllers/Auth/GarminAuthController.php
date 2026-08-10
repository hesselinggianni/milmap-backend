<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthHandoffToken;
use App\Models\GarminAccount;
use App\Models\GarminOauthState;
use App\Services\GarminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Koppelt een Garmin Connect-account aan een al-ingelogde MilMap-gebruiker
 * (OAuth 2.0 + PKCE). Beide endpoints zijn publiek: de webview/browser-
 * navigatie hiernaartoe draagt geen Authorization-header mee — dezelfde reden
 * waarom externalBilling.js een AuthHandoffToken gebruikt om de sessie over
 * te dragen (zie HandoffController). redirect() wisselt dat handoff-token in
 * voor de user zonder een nieuw Sanctum-token te minten (dat is hier niet
 * nodig, alleen de identiteit).
 */
class GarminAuthController extends Controller
{
    public function __construct(private GarminService $garmin) {}

    /** GET /api/v1/auth/garmin/redirect?handoff=&platform=web|native */
    public function redirect(Request $request)
    {
        $data = $request->validate([
            'handoff' => 'required|string',
            'platform' => 'required|in:web,native',
        ]);

        $handoff = AuthHandoffToken::where('token', $data['handoff'])->first();
        if (! $handoff || ! $handoff->isValid()) {
            return $this->failureRedirect($data['platform'], 'invalid_handoff');
        }
        $handoff->forceFill(['used_at' => now()])->save();

        if (! $this->garmin->isConfigured()) {
            Log::warning('Garmin OAuth-redirect geprobeerd zonder geconfigureerde client-id/secret.');
            return $this->failureRedirect($data['platform'], 'not_configured');
        }

        $codeVerifier = $this->garmin->newCodeVerifier();
        $state = Str::random(48);

        GarminOauthState::create([
            'user_id' => $handoff->user_id,
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'platform' => $data['platform'],
            'expires_at' => now()->addMinutes(10),
        ]);

        return redirect()->away(
            $this->garmin->buildAuthorizeUrl($state, $this->garmin->codeChallenge($codeVerifier))
        );
    }

    /** GET /api/v1/auth/garmin/callback?code=&state= */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        $oauthState = $state ? GarminOauthState::where('state', $state)->first() : null;
        if (! $code || ! $oauthState || ! $oauthState->isValid()) {
            return $this->failureRedirect($oauthState->platform ?? 'web', 'invalid_state');
        }
        $oauthState->forceFill(['used_at' => now()])->save();

        try {
            $token = $this->garmin->exchangeCodeForToken($code, $oauthState->code_verifier);
            $garminUserId = $this->garmin->fetchGarminUserId($token['access_token']);
            $devices = $this->garmin->fetchDevices($token['access_token']);

            GarminAccount::updateOrCreate(
                ['user_id' => $oauthState->user_id],
                [
                    'garmin_user_id' => $garminUserId,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                    'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                    'scope' => $token['scope'] ?? null,
                    'devices' => $devices,
                    'connected_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Garmin OAuth-callback mislukt: ' . $e->getMessage());
            return $this->failureRedirect($oauthState->platform, 'exchange_failed');
        }

        return $this->successRedirect($oauthState->platform);
    }

    private function successRedirect(string $platform)
    {
        if ($platform === 'native') {
            return response()->view('garmin-callback', ['status' => 'success']);
        }

        return redirect()->away(config('app.frontend_url') . '/account?tab=integrations&garmin=connected');
    }

    private function failureRedirect(string $platform, string $reason)
    {
        if ($platform === 'native') {
            return response()->view('garmin-callback', ['status' => 'error', 'reason' => $reason]);
        }

        return redirect()->away(config('app.frontend_url') . '/account?tab=integrations&garmin=error&reason=' . $reason);
    }
}
