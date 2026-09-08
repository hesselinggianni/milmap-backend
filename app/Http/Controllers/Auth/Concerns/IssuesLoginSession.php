<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Mail\LoginNotification;
use App\Models\User;
use App\Support\DeviceInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Gedeelde "geslaagde login" afhandeling: gearchiveerd-check, verwijderverzoek
 * annuleren, Sanctum-token met device-metadata, login-notificatiemail en de
 * JSON-respons. Gebruikt door zowel het wachtwoord- als het code-inlogpad
 * (LoginController en LoginCodeController) zodat dit gedrag niet uit de pas
 * kan gaan lopen tussen de twee.
 */
trait IssuesLoginSession
{
    protected function finalizeLogin(Request $request, User $user): JsonResponse
    {
        // Gearchiveerd account (>90 dagen ongeverifieerd): geen toegang meer.
        if ($user->isArchived()) {
            throw ValidationException::withMessages([
                'email' => 'Dit account is gearchiveerd omdat het e-mailadres niet binnen 90 dagen is bevestigd. Maak opnieuw een account aan.',
            ]);
        }

        // Weer ingelogd binnen de bedenktijd = spijt, verwijderverzoek vervalt.
        if ($user->deletion_purge_at) {
            $user->forceFill([
                'deletion_requested_at' => null,
                'deletion_purge_at'     => null,
            ])->save();
            Log::info('[account] verwijderverzoek geannuleerd door inloggen', ['user_id' => $user->id]);
        }

        $newToken = $user->createToken('API Token', ['user']);

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

    private function sendLoginNotification(Request $request, User $user): void
    {
        $ip = $request->ip();
        $location = $this->resolveLocation($ip);
        $device = $this->parseDevice($request->userAgent() ?? 'Onbekend');
        $loginTime = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');

        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[login] login-notificatie overgeslagen: ongeldig e-mailadres voor user ' . $user->id);

            return;
        }

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
