<?php

namespace App\Support;

use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Http\Request;

/**
 * Herkomst van een aanmelding (pagina-URL, externe verwijzer, land) om bij het
 * nieuwe account te bewaren, zodat het periodieke admin-overzicht
 * (AdminDigestService) kan tonen waar mensen vandaan komen. Voorheen stond dit
 * alleen in de realtime admin-mail.
 *
 * Alle waarden zijn best-effort: ontbreekt er iets (oude app-versie, geen
 * Referer-header, geen GeoIP-resultaat) dan is dat veld gewoon null.
 */
class SignupOrigin
{
    private const MAX = 500;

    /** Kolommen voor `users` (signup_*). */
    public static function forUser(Request $request): array
    {
        [$url, $referrer] = self::urlAndReferrer($request);

        return [
            'signup_source_url' => $url,
            'signup_referrer'   => $referrer,
            'signup_country'    => GeoIp::country($request),
        ];
    }

    /** Kolommen voor `leads` (source_url/referrer/country). */
    public static function forLead(Request $request): array
    {
        [$url, $referrer] = self::urlAndReferrer($request);

        return [
            'source_url' => $url,
            'referrer'   => $referrer,
            'country'    => GeoIp::country($request),
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function urlAndReferrer(Request $request): array
    {
        // Valt terug op de HTTP Referer-header als de client de URL niet zelf
        // meestuurt (oudere app-versies) — zelfde regel als de realtime mail.
        $url = $request->input('source_url') ?: $request->header('Referer');
        $url = RegisterController::labelSourceUrl(is_string($url) ? $url : null);
        $referrer = $request->input('referrer');

        return [
            self::clip($url),
            self::clip(is_string($referrer) ? $referrer : null),
        ];
    }

    private static function clip(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX);
    }
}
