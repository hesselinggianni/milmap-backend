<?php

namespace App\Services;

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
}
