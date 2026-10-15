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
     * Silent push (iOS: content-available) naar één Connect-apparaat. Faalt nooit
     * hard: zonder APNs-config of token valt de app terug op de 401 bij het
     * volgende verzoek. Config: APNS_KEY_PATH, APNS_KEY_ID, APNS_TEAM_ID,
     * APNS_BUNDLE_ID (en APNS_SANDBOX=true voor development-builds).
     */
    public static function silentToDevice(ConnectDevice $device, array $data): void
    {
        $token = $device->push_token;
        if (!$token || $device->push_platform !== 'ios') {
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
}
