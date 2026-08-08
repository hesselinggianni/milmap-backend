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
 * Inloggen en registreren met Apple ID.
 *
 * De app (iOS, native) en het web leveren allebei hetzelfde `identity_token`
 * aan: een JWT die Apple heeft ondertekend. Hier verifiëren we die tegen
 * Apple's publieke sleutels — we vertrouwen dus nooit op wat de client zegt te
 * zijn, alleen op wat Apple heeft getekend.
 *
 * Belangrijk detail: koppelen gebeurt op `sub` (Apple's onveranderlijke
 * gebruikers-id), niet op e-mail. Gebruikers mogen hun adres verbergen; ze
 * krijgen dan een doorstuuradres als `xyz@privaterelay.appleid.com`. Ook geeft
 * Apple naam en e-mail ALLEEN mee bij de allereerste autorisatie — daarna
 * nooit meer. Wie op e-mail koppelt, maakt bij de tweede login een tweede
 * account aan.
 */
class AppleAuthController extends Controller
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER   = 'https://appleid.apple.com';

    /**
     * POST /api/v1/auth/apple
     *
     * Body: identity_token (verplicht), given_name/family_name (alleen bij de
     * eerste keer aanwezig), platform ('ios' of 'web' — bepaalt welke
     * audience geldig is).
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'identity_token' => ['required', 'string'],
            'given_name'     => ['nullable', 'string', 'max:100'],
            'family_name'    => ['nullable', 'string', 'max:100'],
            'platform'       => ['nullable', 'in:ios,web'],
        ]);

        try {
            $claims = $this->verifyToken($data['identity_token'], $data['platform'] ?? 'ios');
        } catch (\Throwable $e) {
            Log::warning('[apple-auth] token afgekeurd', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Inloggen met Apple is niet gelukt. Probeer het opnieuw.'], 401);
        }

        $appleSub = (string) ($claims['sub'] ?? '');
        if ($appleSub === '') {
            return response()->json(['message' => 'Inloggen met Apple is niet gelukt.'], 401);
        }

        $email = isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null;

        // 1. Kennen we deze Apple-gebruiker al? 2. Zo niet: bestaat er een
        // account met ditzelfde e-mailadres (iemand die eerder met wachtwoord
        // registreerde)? Dan koppelen we Apple daaraan in plaats van een
        // tweede account te maken. 3. Anders: nieuw account.
        $user = User::where('apple_sub', $appleSub)->first();
        $nieuw = false;

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->forceFill(['apple_sub' => $appleSub])->save();
            }
        }

        if (! $user) {
            $user = $this->createUser($appleSub, $email, $data);
            $nieuw = true;
        }

        // Weer ingelogd binnen de bedenktijd = het verwijderverzoek vervalt
        // (zelfde regel als bij de wachtwoord-login).
        if ($user->deletion_purge_at) {
            $user->forceFill(['deletion_requested_at' => null, 'deletion_purge_at' => null])->save();
        }

        $token = $user->createToken('API Token', ['user']);

        return response()->json([
            'token'    => $token->plainTextToken,
            'user'     => $user->fresh(),
            'is_new'   => $nieuw,
        ]);
    }

    /**
     * Verifieer Apple's JWT: handtekening tegen de publieke sleutels, plus
     * uitgever, audience en vervaltijd. JWT::decode controleert exp/iat zelf.
     */
    private function verifyToken(string $jwt, string $platform): array
    {
        $keys = JWK::parseKeySet($this->jwks());
        $claims = (array) JWT::decode($jwt, $keys);

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new \RuntimeException('onverwachte uitgever');
        }

        // iOS-app en web hebben elk hun eigen client-id: de bundle identifier
        // respectievelijk de Services ID. Een token voor de één mag niet
        // gelden voor de ander.
        $verwacht = $platform === 'web'
            ? config('services.apple.service_id')
            : config('services.apple.bundle_id');

        if (! $verwacht || ($claims['aud'] ?? null) !== $verwacht) {
            throw new \RuntimeException('audience klopt niet');
        }

        return $claims;
    }

    /**
     * Apple's publieke sleutels. Een dag cachen: ze roteren zelden, en bij elke
     * login opnieuw ophalen maakt Apple een storingspunt in je inlogflow.
     */
    private function jwks(): array
    {
        return Cache::remember('apple_jwks', now()->addDay(), function () {
            $res = Http::timeout(8)->get(self::JWKS_URL);
            if (! $res->successful()) {
                throw new \RuntimeException('kon Apple-sleutels niet ophalen');
            }

            return $res->json();
        });
    }

    private function createUser(string $appleSub, ?string $email, array $data): User
    {
        // Zonder e-mail (verborgen én geen doorstuuradres) toch een uniek,
        // niet-routeerbaar adres: de rest van de app gaat ervan uit dat elke
        // user er een heeft. .invalid is hiervoor gereserveerd (RFC 2606).
        $email = $email ?: 'apple_' . Str::substr(hash('sha256', $appleSub), 0, 24) . '@milmap.invalid';

        $user = new User([
            'first_name' => $data['given_name'] ?? '',
            'last_name'  => $data['family_name'] ?? '',
            'email'      => $email,
        ]);
        $user->apple_sub = $appleSub;
        // Apple heeft het adres zelf geverifieerd; de e-mailmuur hoeft dus niet
        // opnieuw om bevestiging te vragen.
        $user->email_verified_at = now();
        // Geen wachtwoord: inloggen kan alleen via Apple, tenzij de gebruiker
        // er later zelf een instelt via 'wachtwoord vergeten'.
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
