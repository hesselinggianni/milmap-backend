<?php

namespace App\Services;

use App\Models\GarminAccount;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Garmin Connect Developer Program-koppeling: OAuth 2.0 + PKCE, devicelijst,
 * routes pushen als "course" (Training API) en opgenomen activiteiten ophalen
 * (Activity API, via een Ping-webhook — zie GarminWebhookController).
 *
 * LET OP — Garmin Connect Developer Program-toegang is bij het schrijven van
 * deze klasse nog niet aangevraagd. Alle endpoint-URL's, scope-namen en
 * payload-vormen hieronder zijn daarom AANNAMES op basis van Garmin's
 * publieke OAuth2+PKCE-migratie, niet geverifieerd tegen de actuele
 * portaal-documentatie. Elke regel met "VERIFY" moet worden nagelopen zodra
 * er portaaltoegang is — dat is dan het ENIGE bestand dat hoeft te worden
 * aangepast om de integratie live te zetten.
 */
class GarminService
{
    // VERIFY: exacte OAuth2/PKCE-endpoints tegen de Garmin Connect Developer
    // Program-documentatie zodra portaaltoegang beschikbaar is.
    private const AUTHORIZE_URL = 'https://connect.garmin.com/oauth2Confirm';
    private const TOKEN_URL = 'https://diauth.garmin.com/di-oauth2-service/oauth/token';

    // VERIFY: basis-URL + paden van de Training-/Activity-API's.
    private const API_BASE = 'https://apis.garmin.com';
    private const USER_ID_PATH = '/wellness-api/rest/user/id';
    private const DEVICES_PATH = '/wellness-api/rest/user/devices';
    private const COURSE_PATH = '/training-api/course';

    // VERIFY: exacte scope-naam/namen die Garmin voor course-push +
    // activity-pull vereist (gescheiden door spaties in de authorize-URL).
    private const SCOPES = 'ACTIVITY_EXPORT COURSE_IMPORT';

    /** Platform-brede client-id: DB-instelling wint van .env. */
    public function clientId(): ?string
    {
        $id = Setting::get('garmin_client_id', '');
        return $id ?: (config('services.garmin.client_id') ?: null);
    }

    /** Platform-brede client-secret: DB-instelling wint van .env. */
    public function clientSecret(): ?string
    {
        $secret = Setting::get('garmin_client_secret', '');
        return $secret ?: (config('services.garmin.client_secret') ?: null);
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    public function redirectUri(): string
    {
        return config('services.garmin.redirect_uri');
    }

    /** Genereert een PKCE code_verifier (43-128 tekens, RFC 7636). */
    public function newCodeVerifier(): string
    {
        return Str::random(64);
    }

    /** S256 code_challenge voor een gegeven code_verifier. */
    public function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /** Bouwt de volledige "ga naar Garmin en log in"-URL. VERIFY: parameter-namen. */
    public function buildAuthorizeUrl(string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * Wisselt de authorization code in voor een access/refresh-token.
     * VERIFY: exacte body-vorm (form-encoded vs JSON) en response-shape.
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:?string}
     */
    public function exchangeCodeForToken(string $code, string $codeVerifier): array
    {
        $resp = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $this->redirectUri(),
        ])->throw();

        return $resp->json();
    }

    /** Ververst een verlopen/bijna-verlopen access-token en slaat het meteen op. */
    public function refreshToken(GarminAccount $account): void
    {
        $resp = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $account->refresh_token,
        ])->throw()->json();

        $account->update([
            'access_token' => $resp['access_token'],
            'refresh_token' => $resp['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($resp['expires_in'] ?? 3600)),
        ]);
    }

    /** Ververst het token indien nodig en geeft altijd een geldig access-token terug. */
    public function ensureFreshAccessToken(GarminAccount $account): string
    {
        if ($account->isTokenExpiringSoon()) {
            $this->refreshToken($account);
            $account->refresh();
        }

        return $account->access_token;
    }

    /** Garmin's opaque per-app user-id voor de zojuist gekoppelde gebruiker. VERIFY: pad + shape. */
    public function fetchGarminUserId(string $accessToken): string
    {
        $resp = Http::withToken($accessToken)->get(self::API_BASE . self::USER_ID_PATH)->throw()->json();

        return (string) ($resp['userId'] ?? $resp['id'] ?? '');
    }

    /**
     * Devicelijst van het gekoppelde account, voor de "Garmin-devicetype"-label.
     * VERIFY: pad + shape (aanname: [{deviceId, productDisplayName}, ...]).
     *
     * @return array<int, array{device_id:string, model_name:string}>
     */
    public function fetchDevices(string $accessToken): array
    {
        $resp = Http::withToken($accessToken)->get(self::API_BASE . self::DEVICES_PATH)->throw()->json();

        return collect($resp['devices'] ?? $resp ?? [])
            ->map(fn ($d) => [
                'device_id' => (string) ($d['deviceId'] ?? $d['unitId'] ?? ''),
                'model_name' => (string) ($d['productDisplayName'] ?? $d['deviceTypePk'] ?? 'Garmin'),
            ])
            ->values()
            ->all();
    }

    /**
     * Pusht een route als "course" naar Garmin Connect zodat hij naar het
     * device synct. VERIFY: exacte payload-vorm van de Training API (JSON met
     * geordende punten vs. een GPX/TCX-bestand-upload — hier aangenomen: JSON).
     *
     * @param  array<int, array{lat:float, lon:float}>  $points
     * @return string  Garmin's course-id, voor garmin_course_id.
     */
    public function pushCourse(GarminAccount $account, string $name, array $points): string
    {
        $token = $this->ensureFreshAccessToken($account);

        $resp = Http::withToken($token)->post(self::API_BASE . self::COURSE_PATH, [
            'courseName' => $name,
            'coursePoints' => array_map(fn ($p) => [
                'latitude' => $p['lat'],
                'longitude' => $p['lon'],
            ], $points),
        ])->throw()->json();

        return (string) ($resp['courseId'] ?? $resp['id'] ?? '');
    }

    /**
     * Haalt de autoritatieve activiteitdata op bij Garmin (nooit de
     * ping-payload zelf vertrouwen als brondata — zie GarminWebhookController).
     * VERIFY: exacte response-shape, met name het track-punten-formaat.
     */
    public function fetchActivityData(GarminAccount $account, string $fetchUrl): array
    {
        $token = $this->ensureFreshAccessToken($account);

        return Http::withToken($token)->get($fetchUrl)->throw()->json();
    }
}
