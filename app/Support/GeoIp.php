<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

/**
 * Privacy-vriendelijke land-bepaling voor analytics.
 *
 * Geeft alleen een ISO-2 landcode terug (bv. "NL") — nooit het IP-adres zelf.
 * Volgorde:
 *   1. Cloudflare-header `CF-IPCountry` (gratis, instant, als de app achter
 *      Cloudflare draait);
 *   2. anders de lokale DB-IP "IP to Country Lite"-database (CC BY 4.0,
 *      maandelijks ververst via `geoip:update`) — het IP verlaat de server niet;
 *   3. alleen als die database ontbreekt: een HTTPS-lookup bij country.is.
 *
 * De cache-sleutel is een HMAC van het IP, zodat er geen IP-adressen in de
 * cache staan. Privé/loopback-IP's leveren null. De lookup faalt nooit hard.
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

        // 2. Lokale database: geen externe call, geen cache nodig.
        $path = self::databasePath();
        if (is_file($path)) {
            return self::lookupLocal($path, $ip);
        }

        // 3. Terugval zonder lokale database: versleutelde externe lookup, één
        //    keer per IP per dag. Ook null cachen we, zodat een onbekend IP niet
        //    bij elke beacon opnieuw een externe call triggert.
        $key = 'geoip:' . hash_hmac('sha256', $ip, (string) config('app.key'));

        return Cache::remember($key, now()->addHours(24), function () use ($ip) {
            try {
                // API-verbruik: alleen echte upstream-calls (cache-hits zouden elke beacon een write kosten).
                $json = \App\Services\ApiUsageMeter::measure('country_is', 'geoip',
                    fn () => Http::timeout(2)->get('https://api.country.is/' . rawurlencode($ip)))->json();
                return self::normalize($json['country'] ?? null);
            } catch (\Throwable $e) {
                // Stil: analytics mag nooit falen op een geo-lookup.
                return null;
            }
        });
    }

    public static function databasePath(): string
    {
        return storage_path('app/geoip/dbip-country-lite.mmdb');
    }

    private static function lookupLocal(string $path, string $ip): ?string
    {
        static $reader = null;
        try {
            $reader ??= new Reader($path);
            $record = $reader->get($ip);
            return self::normalize($record['country']['iso_code'] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function normalize(mixed $code): ?string
    {
        $code = strtoupper((string) $code);
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
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
