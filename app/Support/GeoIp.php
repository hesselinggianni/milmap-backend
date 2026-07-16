<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Privacy-vriendelijke land-bepaling voor analytics.
 *
 * Geeft alleen een ISO-2 landcode terug (bv. "NL") — nooit het IP-adres zelf.
 * Volgorde:
 *   1. Cloudflare-header `CF-IPCountry` (gratis, instant, als de app achter
 *      Cloudflare draait);
 *   2. anders een gecachete ip-api.com-lookup per IP (24u cache, zodat we het
 *      free-tier-limiet niet raken en niet per beacon een externe call doen).
 *
 * Privé/loopback-IP's leveren null. De lookup faalt nooit hard.
 */
class GeoIp
{
    public static function country(Request $request): ?string
    {
        // 1. Cloudflare levert de landcode kosteloos mee.
        $cf = strtoupper((string) $request->header('CF-IPCountry'));
        if (preg_match('/^[A-Z]{2}$/', $cf) && ! in_array($cf, ['XX', 'T1'], true)) {
            return $cf;
        }

        $ip = $request->ip();
        if (! $ip || self::isPrivate($ip)) {
            return null;
        }

        // 2. Gecachete externe lookup — één keer per IP per dag. Ook een null-
        //    resultaat cachen we, zodat een onbekend IP niet elke beacon opnieuw
        //    een externe call triggert.
        return Cache::remember('geoip:' . $ip, now()->addHours(24), function () use ($ip) {
            try {
                $resp = Http::timeout(2)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode',
                ]);
                $json = $resp->json();
                if (($json['status'] ?? null) === 'success' && ! empty($json['countryCode'])) {
                    return strtoupper(substr((string) $json['countryCode'], 0, 2));
                }
            } catch (\Throwable $e) {
                // Stil: analytics mag nooit falen op een geo-lookup.
            }

            return null;
        });
    }

    private static function isPrivate(string $ip): bool
    {
        // Publieke, niet-gereserveerde IP's passeren de filter; al het andere
        // (LAN, loopback, link-local) telt als privé → geen lookup.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
