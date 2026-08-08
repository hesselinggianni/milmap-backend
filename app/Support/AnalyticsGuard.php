<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Sluit bekend "eigen" verkeer uit van de first-party analytics
 * (app_events + site_events): loopback (lokale dev) en een handmatig
 * ingestelde lijst IP-adressen (bv. het thuis-/kantoor-IP van het team dat
 * de productie-app gebruikt om te testen). Los van de bot-detectie
 * (BotDetector) en de client-side dev/demo-uitsluiting.
 *
 * Config via .env: ANALYTICS_EXCLUDED_IPS="1.2.3.4,5.6.7.8"
 */
class AnalyticsGuard
{
    public static function isExcluded(Request $request): bool
    {
        $ip = $request->ip();

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        return in_array($ip, self::excludedIps(), true);
    }

    /** @return string[] */
    private static function excludedIps(): array
    {
        $raw = (string) config('services.analytics.excluded_ips', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
