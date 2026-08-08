<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginNotification;
use App\Support\DeviceInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Two-dimensional throttle. A pure per-IP limit has two failure modes:
        //   • shared-NAT lockout — one bad actor behind a carrier-grade NAT /
        //     office gateway locks out every other legitimate user on that IP;
        //   • distributed bypass — an attacker rotating IPs brute-forces a
        //     single account without ever tripping the per-IP counter.
        // So we keep a strict per-ACCOUNT counter (catches IP rotation against
        // one email) plus a looser per-IP counter (catches one host spraying
        // many accounts) and block when EITHER trips. The per-account limit is
        // the tight one, so honest users sharing a NAT aren't punished for a
        // neighbour's failures.
        $emailKey = 'login-attempts:email:' . sha1(mb_strtolower($credentials['email']));
        $ipKey    = 'login-attempts:ip:' . $request->ip();

        if (RateLimiter::tooManyAttempts($emailKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in a few minutes.',
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($emailKey, 900); // 15-min decay
            RateLimiter::hit($ipKey, 900);
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($emailKey);
        RateLimiter::clear($ipKey);

        // Gearchiveerd account (>90 dagen ongeverifieerd): geen toegang meer.
        // De gebruiker moet opnieuw registreren met hetzelfde e-mailadres.
        if ($user->isArchived()) {
            throw ValidationException::withMessages([
                'email' => 'Dit account is gearchiveerd omdat het e-mailadres niet binnen 90 dagen is bevestigd. Maak opnieuw een account aan.',
            ]);
        }

        // Weer ingelogd binnen de bedenktijd = spijt. Het verwijderverzoek
        // vervalt dan; dat is de hele reden dat er 90 dagen tussen zit (zie
        // AccountDeletionController). Bewust hier en niet in een middleware:
        // het moet precies bij een geslaagde inlog gebeuren.
        if ($user->deletion_purge_at) {
            $user->forceFill([
                'deletion_requested_at' => null,
                'deletion_purge_at'     => null,
            ])->save();
            \Illuminate\Support\Facades\Log::info('[account] verwijderverzoek geannuleerd door inloggen', ['user_id' => $user->id]);
        }

        // Scope regular-login tokens to the 'user' ability so they can never
        // satisfy tokenCan('admin') — admin routes require a token minted via
        // the admin login flow (see AdminAuth middleware).
        $newToken = $user->createToken('API Token', ['user']);

        // Device-/herkomst-metadata op het token zodat de gebruiker dit later
        // in Account → Beveiliging als login-historie terugziet en gericht
        // sessies kan intrekken. forceFill omdat dit geen $fillable-velden zijn.
        $newToken->accessToken->forceFill([
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
            'platform'     => DeviceInfo::platform($request),
            'last_used_ip' => $request->ip(),
        ])->save();

        $token = $newToken->plainTextToken;

        $this->sendLoginNotification($request, $user);

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => array_merge($user->toArray(), [
                'verification' => $user->verificationState(),
                'premium'      => $user->premiumState(),
            ]),
            'token' => $token,
        ], 200);
    }

    /**
     * E-mail-eerst auth-flow: geeft terug of een e-mailadres al een (niet-
     * gearchiveerd) account heeft. De client kiest op basis hiervan tussen het
     * inlog- of registratiescherm. Bewust minimaal (alleen een boolean) en
     * zwaar gethrottled (zie route) om account-enumeratie te ontmoedigen.
     */
    public function checkEmail(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', $data['email'])->first();
        // Een gearchiveerd account moet opnieuw registreren, dus behandelen we
        // het als "bestaat niet" → de client toont het registratiescherm.
        $exists = $user && !$user->isArchived();

        return response()->json(['exists' => (bool) $exists]);
    }

    private function sendLoginNotification(Request $request, User $user): void
    {
        $ip = $request->ip();
        $location = $this->resolveLocation($ip);
        $device = $this->parseDevice($request->userAgent() ?? 'Onbekend');
        $loginTime = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');

        // Geen zin om te versturen naar een leeg/ongeldig adres: de mailserver
        // weigert dat met "550 No such recipient here" en het vult onze logs met
        // warnings. Stil overslaan (zelfde guard als EmailVerificationService).
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[login] login-notificatie overgeslagen: ongeldig e-mailadres voor user ' . $user->id);

            return;
        }

        // Een mislukte notificatiemail (SMTP down/misconfig) mag het inloggen
        // NOOIT blokkeren — stil loggen en doorgaan.
        try {
            Mail::to($user->email)->send(
                new LoginNotification($user->name ?? $user->email, $ip, $location, $device, $loginTime)
            );
        } catch (\Throwable $e) {
            Log::warning('[login] kon login-notificatie niet versturen: ' . $e->getMessage());
        }
    }

    private function resolveLocation(string $ip): string
    {
        // Lokale / private IP's kunnen niet worden opgezocht
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return 'Lokaal netwerk';
        }

        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}?fields=status,city,regionName,country&lang=nl");

            if ($response->ok()) {
                $data = $response->json();
                if (($data['status'] ?? '') === 'success') {
                    $parts = array_filter([$data['city'] ?? null, $data['regionName'] ?? null, $data['country'] ?? null]);
                    return implode(', ', $parts) ?: 'Onbekend';
                }
            }
        } catch (\Throwable) {
            // Geen locatie beschikbaar — stil falen
        }

        return 'Onbekend';
    }

    private function parseDevice(string $userAgent): string
    {
        $browser = 'Onbekende browser';
        $os = 'Onbekend OS';

        // Browser detectie
        if (str_contains($userAgent, 'Edg/')) $browser = 'Microsoft Edge';
        elseif (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera')) $browser = 'Opera';
        elseif (str_contains($userAgent, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($userAgent, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($userAgent, 'Safari')) $browser = 'Safari';

        // OS detectie
        if (str_contains($userAgent, 'Windows NT')) $os = 'Windows';
        elseif (str_contains($userAgent, 'Mac OS X')) $os = 'macOS';
        elseif (str_contains($userAgent, 'Android')) $os = 'Android';
        elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) $os = 'iOS';
        elseif (str_contains($userAgent, 'Linux')) $os = 'Linux';

        return "{$browser} op {$os}";
    }
}
