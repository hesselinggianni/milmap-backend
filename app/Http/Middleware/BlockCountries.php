<?php

namespace App\Http\Middleware;

use App\Support\GeoIp;
use Closure;
use Illuminate\Http\Request;

/**
 * Weigert alle API-verkeer uit uitgesloten landen (config/geoblock.php) met 451.
 * Onbekend land (privé-IP, lookup mislukt) wordt doorgelaten: fail-open.
 * Webhooks van derden, deploy/OTA en health blijven bereikbaar.
 */
class BlockCountries
{
    public static function isBlocked(Request $request): bool
    {
        $blocked = (array) config('geoblock.countries', []);
        if (! $blocked) {
            return false;
        }
        $country = GeoIp::country($request);

        return $country !== null && in_array($country, $blocked, true);
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/v1/geo/status', 'api/v1/ota/*', 'api/v1/deploy/*', 'api/v1/status/*', 'api/v1/*webhook*', 'api/v1/stripe/*')) {
            return $next($request);
        }

        if (self::isBlocked($request)) {
            return response()->json([
                'message' => 'MilMap is not available in your country.',
                'code'    => 'geo_blocked',
            ], 451);
        }

        return $next($request);
    }
}
