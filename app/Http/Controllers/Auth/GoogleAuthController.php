<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inloggen en registreren met Google.
 *
 * Zelfde opzet als AppleAuthController: de client (web/iOS/Android) levert
 * een `id_token` aan — een JWT die Google zelf heeft ondertekend — en wij
 * verifiëren die tegen Google's publieke sleutels. We vertrouwen dus nooit op
 * wat de client zegt te zijn, alleen op wat Google heeft getekend.
 *
 * Koppelen gebeurt op `sub` (Google's onveranderlijke gebruikers-id), niet op
 * e-mail — al geeft Google (anders dan Apple) wél altijd e-mail + naam mee,
 * dus koppelen op e-mail zou hier ook hadden gewerkt. We houden 'm voor de
 * consistentie en omdat een gebruiker zijn Google-e-mailadres later kan
 * wijzigen zonder dat `sub` verandert.
 */
class GoogleAuthController extends Controller
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS  = ['https://accounts.google.com', 'accounts.google.com'];

    /**
     * POST /api/v1/auth/google
     *
     * Body: id_token (verplicht), platform ('web', 'ios' of 'android' —
     * bepaalt welke audience geldig is).
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'platform' => ['nullable', 'in:web,ios,android'],
        ]);

        try {
            $claims = $this->verifyToken($data['id_token'], $data['platform'] ?? 'web');
        } catch (\Throwable $e) {
            Log::warning('[google-auth] token afgekeurd', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Inloggen met Google is niet gelukt. Probeer het opnieuw.'], 401);
        }

        $googleSub = (string) ($claims['sub'] ?? '');
        if ($googleSub === '') {
            return response()->json(['message' => 'Inloggen met Google is niet gelukt.'], 401);
        }

        $email = isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null;

        // 1. Kennen we deze Google-gebruiker al? 2. Zo niet: bestaat er een
        // account met ditzelfde e-mailadres (wachtwoord-account of Apple)?
        // Dan koppelen we Google eraan i.p.v. een tweede account te maken.
        // 3. Anders: nieuw account.
        $user = User::where('google_sub', $googleSub)->first();
        $nieuw = false;

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->forceFill(['google_sub' => $googleSub])->save();
            }
        }

        if (! $user) {
            $user = $this->createUser($googleSub, $email, $claims);
            $nieuw = true;
        }

        // Weer ingelogd binnen de bedenktijd = het verwijderverzoek vervalt
        // (zelfde regel als bij de wachtwoord-login / Apple-login).
        if ($user->deletion_purge_at) {
            $user->forceFill(['deletion_requested_at' => null, 'deletion_purge_at' => null])->save();
        }

        $token = $user->createToken('API Token', ['user']);

        return response()->json([
            'token'  => $token->plainTextToken,
            'user'   => $user->fresh(),
            'is_new' => $nieuw,
        ]);
    }

    /**
     * Verifieer Google's JWT: handtekening tegen de publieke sleutels, plus
     * uitgever, audience en vervaltijd. JWT::decode controleert exp/iat zelf.
     */
    private function verifyToken(string $jwt, string $platform): array
    {
        $keys = JWK::parseKeySet($this->jwks());
        $claims = (array) JWT::decode($jwt, $keys);

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new \RuntimeException('onverwachte uitgever');
        }

        $verwacht = match ($platform) {
            'ios'     => config('services.google.ios_client_id'),
            'android' => config('services.google.android_client_id'),
            default   => config('services.google.web_client_id'),
        };

        if (! $verwacht || ($claims['aud'] ?? null) !== $verwacht) {
            throw new \RuntimeException('audience klopt niet');
        }

        return $claims;
    }

    /**
     * Google's publieke sleutels. Een dag cachen: ze roteren zelden, en bij
     * elke login opnieuw ophalen maakt Google een storingspunt in je
     * inlogflow.
     */
    private function jwks(): array
    {
        return Cache::remember('google_jwks', now()->addDay(), function () {
            $res = Http::timeout(8)->get(self::JWKS_URL);
            if (! $res->successful()) {
                throw new \RuntimeException('kon Google-sleutels niet ophalen');
            }

            return $res->json();
        });
    }

    private function createUser(string $googleSub, ?string $email, array $claims): User
    {
        // Google geeft altijd een geverifieerd e-mailadres mee, maar voor de
        // zeldzame edge-case zonder e-mail toch een uniek, niet-routeerbaar
        // adres — de rest van de app gaat ervan uit dat elke user er een
        // heeft. .invalid is hiervoor gereserveerd (RFC 2606).
        $email = $email ?: 'google_' . Str::substr(hash('sha256', $googleSub), 0, 24) . '@milmap.invalid';

        $givenName  = (string) ($claims['given_name'] ?? '');
        $familyName = (string) ($claims['family_name'] ?? '');
        if ($givenName === '' && $familyName === '' && ! empty($claims['name'])) {
            // Sommige Google-accounts geven alleen 'name' (volledige naam) mee.
            $givenName = trim((string) $claims['name']);
        }

        $user = new User([
            'first_name' => $givenName,
            'last_name'  => $familyName,
            'email'      => $email,
        ]);
        $user->google_sub = $googleSub;
        // Google heeft het adres zelf geverifieerd; de e-mailmuur hoeft dus
        // niet opnieuw om bevestiging te vragen.
        $user->email_verified_at = now();
        // Geen wachtwoord: inloggen kan alleen via Google, tenzij de
        // gebruiker er later zelf een instelt via 'wachtwoord vergeten'.
        $user->password = bcrypt(Str::random(48));
        $user->save();

        try {
            Lead::markConverted($email);
        } catch (\Throwable $e) {
            /* niet kritiek */
        }

        return $user;
    }
}
