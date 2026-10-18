<?php

namespace App\Services;

use App\Models\ConnectDevice;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * STUB voor echte push-aflevering. Een melding naar een gesloten app vereist een
 * FCM/APNs-subsysteem (device-tokens + provider-credentials + native config) dat nog
 * NIET bestaat. De in-app melding (App\Models\AppNotification) wordt al wél aangemaakt,
 * zodat de gebruiker het bericht in de app onder Meldingen ziet.
 *
 * Wanneer push later wél gebouwd wordt, is dit de enige plek die hoeft te veranderen:
 * haal hier de device-tokens van de gebruiker op en stuur de payload naar FCM/APNs.
 */
class PushSender
{
    public static function send(int $userId, string $title, ?string $body = null, array $payload = []): void
    {
        // No-op (stub). Loggen zodat zichtbaar is wat er verstuurd zou zijn.
        Log::info('[push-stub] push zou worden verstuurd', [
            'user_id' => $userId,
            'title'   => $title,
            'payload' => $payload,
        ]);
    }

    /**
     * Zichtbare melding (titel + tekst) naar alle toestellen van een gebruiker die
     * een push-token hebben gemeld. Faalt nooit hard; zonder APNs/FCM-config of
     * zonder toestemming op het toestel gebeurt er niets. `$data` komt als
     * custom payload mee (strings) zodat de app bij een tik de juiste route opent.
     */
    public static function alertToUser(int $userId, string $title, ?string $body = null, array $data = []): int
    {
        $sent = 0;
        $devices = ConnectDevice::where('user_id', $userId)
            ->whereNotNull('push_token')->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')->limit(10)->get();

        foreach ($devices as $device) {
            try {
                $ok = $device->push_platform === 'android'
                    ? self::fcmAlert($device, $title, $body, $data)
                    : ($device->push_platform === 'ios' ? self::apnsAlert($device, $title, $body, $data) : false);
                $sent += $ok ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[push] melding mislukt: ' . $e->getMessage(), ['device' => $device->id]);
            }
        }

        return $sent;
    }

    private static function apnsAlert(ConnectDevice $device, string $title, ?string $body, array $data): bool
    {
        $keyPath = env('APNS_KEY_PATH');
        $keyId   = env('APNS_KEY_ID');
        $teamId  = env('APNS_TEAM_ID');
        $bundle  = env('APNS_BUNDLE_ID', 'nl.milmap.app');
        if (!$keyPath || !$keyId || !$teamId || !is_readable($keyPath)) {
            Log::warning('[push] APNs niet geconfigureerd, melding overgeslagen');
            return false;
        }

        $jwt = Cache::remember('apns-jwt', 40 * 60, fn () => JWT::encode(
            ['iss' => $teamId, 'iat' => time()], file_get_contents($keyPath), 'ES256', $keyId
        ));
        $host = env('APNS_SANDBOX') ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        $ch = curl_init("https://{$host}/3/device/{$device->push_token}");
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['aps' => ['alert' => ['title' => $title, 'body' => (string) $body], 'sound' => 'default']] + array_map('strval', $data)),
            CURLOPT_HTTPHEADER => [
                "authorization: bearer {$jwt}",
                "apns-topic: {$bundle}",
                'apns-push-type: alert',
                'apns-priority: 10',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            return true;
        }
        // Token is niet meer geldig (app verwijderd / ander toestel): niet blijven proberen.
        if ($status === 410 || ($status === 400 && str_contains((string) $resp, 'BadDeviceToken'))) {
            $device->forceFill(['push_token' => null, 'push_platform' => null])->save();
        }
        Log::warning('[push] APNs weigerde melding', ['status' => $status, 'body' => $resp, 'device' => $device->id]);
        return false;
    }

    private static function fcmAlert(ConnectDevice $device, string $title, ?string $body, array $data): bool
    {
        $path = env('FCM_SERVICE_ACCOUNT_PATH');
        if (!$path || !is_readable($path)) {
            Log::warning('[push] FCM niet geconfigureerd, melding overgeslagen');
            return false;
        }

        $sa = json_decode(file_get_contents($path), true);
        $access = Cache::remember('fcm-access-token', 50 * 60, function () use ($sa) {
            $now = time();
            $assertion = JWT::encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $sa['private_key'], 'RS256');
            return \Illuminate\Support\Facades\Http::asForm()->timeout(5)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])->json('access_token');
        });

        $res = \Illuminate\Support\Facades\Http::withToken($access)->timeout(5)
            ->post("https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send", [
                'message' => [
                    'token' => $device->push_token,
                    'notification' => ['title' => $title, 'body' => (string) $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'HIGH'],
                ],
            ]);
        if ($res->successful()) {
            return true;
        }
        if ($res->status() === 404 || str_contains($res->body(), 'UNREGISTERED')) {
            $device->forceFill(['push_token' => null, 'push_platform' => null])->save();
        }
        Log::warning('[push] FCM weigerde melding', ['status' => $res->status(), 'body' => $res->body(), 'device' => $device->id]);
        return false;
    }

    /**
     * Silent push (iOS: content-available) naar één Connect-apparaat. Faalt nooit
     * hard: zonder APNs-config of token valt de app terug op de 401 bij het
     * volgende verzoek. Config: APNS_KEY_PATH, APNS_KEY_ID, APNS_TEAM_ID,
     * APNS_BUNDLE_ID (en APNS_SANDBOX=true voor development-builds).
     */
    public static function silentToDevice(ConnectDevice $device, array $data): void
    {
        $token = $device->push_token;
        if (!$token) {
            return;
        }
        if ($device->push_platform === 'android') {
            self::fcmSilent($token, $data, $device->id);
            return;
        }
        if ($device->push_platform !== 'ios') {
            return;
        }
        $keyPath = env('APNS_KEY_PATH');
        $keyId = env('APNS_KEY_ID');
        $teamId = env('APNS_TEAM_ID');
        $bundle = env('APNS_BUNDLE_ID', 'nl.milmap.app');
        if (!$keyPath || !$keyId || !$teamId || !is_readable($keyPath)) {
            Log::warning('[push] APNs niet geconfigureerd, silent push overgeslagen');
            return;
        }

        try {
            // APNs-JWT mag 20–60 min hergebruikt worden.
            $jwt = Cache::remember('apns-jwt', 40 * 60, fn () => JWT::encode(
                ['iss' => $teamId, 'iat' => time()],
                file_get_contents($keyPath),
                'ES256',
                $keyId
            ));
            $host = env('APNS_SANDBOX') ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
            $ch = curl_init("https://{$host}/3/device/{$token}");
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['aps' => ['content-available' => 1]] + $data),
                CURLOPT_HTTPHEADER => [
                    "authorization: bearer {$jwt}",
                    "apns-topic: {$bundle}",
                    'apns-push-type: background',
                    'apns-priority: 5',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status !== 200) {
                Log::warning('[push] APNs weigerde silent push', ['status' => $status, 'body' => $body, 'device' => $device->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[push] silent push mislukt: ' . $e->getMessage());
        }
    }

    /**
     * Android: FCM HTTP v1 data-bericht (hoge prioriteit). Config:
     * FCM_SERVICE_ACCOUNT_PATH = Firebase service-account JSON (project_id staat erin).
     */
    private static function fcmSilent(string $token, array $data, int $deviceId): void
    {
        $path = env('FCM_SERVICE_ACCOUNT_PATH');
        if (!$path || !is_readable($path)) {
            Log::warning('[push] FCM niet geconfigureerd, silent push overgeslagen');
            return;
        }

        try {
            $sa = json_decode(file_get_contents($path), true);
            $access = Cache::remember('fcm-access-token', 50 * 60, function () use ($sa) {
                $now = time();
                $assertion = JWT::encode([
                    'iss' => $sa['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], $sa['private_key'], 'RS256');
                $r = \Illuminate\Support\Facades\Http::asForm()->timeout(5)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
                return $r->json('access_token');
            });

            $res = \Illuminate\Support\Facades\Http::withToken($access)->timeout(5)
                ->post("https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token,
                        // FCM-data moet string => string zijn.
                        'data' => array_map('strval', $data),
                        'android' => ['priority' => 'HIGH'],
                    ],
                ]);
            if (!$res->successful()) {
                Log::warning('[push] FCM weigerde silent push', ['status' => $res->status(), 'body' => $res->body(), 'device' => $deviceId]);
            }
        } catch (\Throwable $e) {
            Log::warning('[push] FCM silent push mislukt: ' . $e->getMessage());
        }
    }
}
