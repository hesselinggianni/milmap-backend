<?php

namespace App\Http\Controllers;

use App\Mail\AppLockTripped;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Beveiligings-acties die de gebruiker zelf vanuit de app kan triggeren.
 *
 * Voor nu: app-lock-tripped. Wanneer iemand 10x de verkeerde PIN invoert
 * in de native/web app belt de client deze endpoint:
 *   1. Alle Sanctum-tokens van deze gebruiker worden ingetrokken (logout op
 *      álle apparaten — ook de huidige sessie).
 *   2. Er gaat een security-mail uit zodat de eigenaar weet wat er is gebeurd
 *      en zijn wachtwoord kan aanpassen.
 *
 * Throttling: 3x per uur per account zodat een misbruiker de mailbox van het
 * slachtoffer niet kan volspammen door bewust herhaaldelijk de PIN te
 * blokkeren. Het 1-uurs UI-lockslot werkt sowieso lokaal op het toestel,
 * dus extra mails toevoegen zou geen security-voordeel meer geven.
 */
class SecurityController extends Controller
{
    public function appLockTripped(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Niet ingelogd.'], 401);
        }

        // Throttle per account zodat de mailbox van het slachtoffer veilig blijft.
        $key = 'app-lock-tripped:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            // Stil 200 zodat de client zijn lokale lockout-flow gewoon doorzet
            // en niet ergens vastloopt — er is ook geen nut in een fout: het
            // toestel is lokaal al vergrendeld.
            return response()->json(['ok' => true, 'throttled' => true]);
        }
        RateLimiter::hit($key, 3600);

        $ip       = $request->ip();
        $location = $this->resolveLocation($ip);
        $device   = $this->parseDevice($request->userAgent() ?? 'Onbekend');
        $time     = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');
        $name     = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                    ?: ($user->name ?? null);

        try {
            Mail::to($user->email)->send(new AppLockTripped(
                $name, $ip, $location, $device, $time,
            ));
        } catch (\Throwable $e) {
            Log::warning('[security] app-lock mail failed: ' . $e->getMessage());
        }

        // Intrekken van ALLE tokens (ook de huidige). De client is op dit punt
        // toch al vergrendeld voor 1 uur — bij de volgende inlogpoging moet
        // de gebruiker zich opnieuw authenticeren met e-mail + wachtwoord.
        try {
            $user->tokens()->delete();
        } catch (\Throwable $e) {
            Log::warning('[security] token revoke failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    // ── Helpers (1-op-1 gekopieerd uit LoginController patroon) ─

    protected function resolveLocation(string $ip): string
    {
        if (in_array($ip, ['127.0.0.1', '::1'], true)
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')) {
            return 'Lokaal netwerk';
        }
        try {
            $response = Http::timeout(3)->get(
                "http://ip-api.com/json/{$ip}?fields=status,city,regionName,country&lang=nl"
            );
            if ($response->ok()) {
                $data = $response->json();
                if (($data['status'] ?? '') === 'success') {
                    $parts = array_filter([
                        $data['city'] ?? null,
                        $data['regionName'] ?? null,
                        $data['country'] ?? null,
                    ]);
                    return implode(', ', $parts) ?: 'Onbekend';
                }
            }
        } catch (\Throwable) {
            // stil falen
        }
        return 'Onbekend';
    }

    protected function parseDevice(string $userAgent): string
    {
        $browser = 'Onbekende browser';
        $os = 'Onbekend OS';
        if (str_contains($userAgent, 'Edg/')) $browser = 'Microsoft Edge';
        elseif (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera')) $browser = 'Opera';
        elseif (str_contains($userAgent, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($userAgent, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($userAgent, 'Safari')) $browser = 'Safari';

        if (str_contains($userAgent, 'Windows NT')) $os = 'Windows';
        elseif (str_contains($userAgent, 'Mac OS X')) $os = 'macOS';
        elseif (str_contains($userAgent, 'Android')) $os = 'Android';
        elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) $os = 'iOS';
        elseif (str_contains($userAgent, 'Linux')) $os = 'Linux';

        return "{$browser} op {$os}";
    }
}
