<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Leidt leesbare device-/client-info af uit een request. Eén bron van waarheid
 * voor de login-notificatie, de security-mails én de sessie-/login-historie,
 * zodat "Chrome op macOS" overal hetzelfde wordt geparsed.
 */
class DeviceInfo
{
    /**
     * Genormaliseerde client-soort: 'web' | 'ios' | 'android' | 'desktop' | 'unknown'.
     * De client (Capacitor/Electron) stuurt dit expliciet mee via de
     * X-Milmap-Client header; valt anders terug op user-agent-detectie.
     */
    public static function platform(Request $request): string
    {
        $hinted = strtolower(trim((string) $request->header('X-Milmap-Client', '')));
        if (in_array($hinted, ['web', 'ios', 'android', 'desktop'], true)) {
            return $hinted;
        }

        return self::platformFromUa($request->userAgent());
    }

    /**
     * Best-effort client-soort puur op basis van de user-agent. Gebruikt als
     * fallback voor tokens die vóór deze feature zijn aangemaakt (geen
     * opgeslagen platform) of wanneer de X-Milmap-Client header ontbreekt.
     */
    public static function platformFromUa(?string $ua): string
    {
        $ua = $ua ?? '';
        if (str_contains($ua, 'Electron')) return 'desktop';
        if (str_contains($ua, 'Android')) return 'android';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iOS')) return 'ios';
        if ($ua !== '') return 'web';

        return 'unknown';
    }

    /**
     * Leesbare apparaat-omschrijving, bijv. "Chrome op macOS".
     */
    public static function device(?string $userAgent): string
    {
        $userAgent = $userAgent ?: '';

        $browser = 'Onbekende browser';
        if (str_contains($userAgent, 'Edg/')) $browser = 'Microsoft Edge';
        elseif (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera')) $browser = 'Opera';
        elseif (str_contains($userAgent, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($userAgent, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($userAgent, 'Safari')) $browser = 'Safari';

        $os = 'Onbekend OS';
        if (str_contains($userAgent, 'Windows NT')) $os = 'Windows';
        elseif (str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh')) $os = 'macOS';
        elseif (str_contains($userAgent, 'Android')) $os = 'Android';
        elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) $os = 'iOS';
        elseif (str_contains($userAgent, 'Linux')) $os = 'Linux';

        return "{$browser} op {$os}";
    }
}
