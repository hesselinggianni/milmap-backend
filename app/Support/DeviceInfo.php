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

    /**
     * Hardware-/app-info die de native app meestuurt (Capacitor Device.getInfo).
     * Alleen aanwezige, geldige headers worden teruggegeven, zodat een oudere
     * app-build bestaande waarden niet met null overschrijft.
     */
    public static function hardware(Request $request): array
    {
        $map = [
            'vendor_id'   => ['X-Milmap-Device-Id', 64],
            'model'       => ['X-Milmap-Device-Model', 80],
            'os_version'  => ['X-Milmap-Os-Version', 40],
            'app_version' => ['X-Milmap-App-Version', 40],
        ];
        $out = [];
        foreach ($map as $column => [$header, $max]) {
            $value = trim((string) $request->header($header, ''));
            if ($value !== '' && preg_match('/^[\w .,()\/+-]+$/u', $value)) {
                $out[$column] = mb_substr($value, 0, $max);
            }
        }

        return $out;
    }

    /**
     * Leesbare naam voor een Apple-hardware-identifier (iPad13,8 → iPad Pro 12.9").
     * Onbekende identifiers worden ongewijzigd teruggegeven.
     */
    public static function modelName(?string $model): ?string
    {
        if (!$model) return null;
        static $names = [
            'iPhone12,1' => 'iPhone 11', 'iPhone12,3' => 'iPhone 11 Pro', 'iPhone12,5' => 'iPhone 11 Pro Max',
            'iPhone13,1' => 'iPhone 12 mini', 'iPhone13,2' => 'iPhone 12', 'iPhone13,3' => 'iPhone 12 Pro', 'iPhone13,4' => 'iPhone 12 Pro Max',
            'iPhone14,4' => 'iPhone 13 mini', 'iPhone14,5' => 'iPhone 13', 'iPhone14,2' => 'iPhone 13 Pro', 'iPhone14,3' => 'iPhone 13 Pro Max',
            'iPhone14,7' => 'iPhone 14', 'iPhone14,8' => 'iPhone 14 Plus', 'iPhone15,2' => 'iPhone 14 Pro', 'iPhone15,3' => 'iPhone 14 Pro Max',
            'iPhone15,4' => 'iPhone 15', 'iPhone15,5' => 'iPhone 15 Plus', 'iPhone16,1' => 'iPhone 15 Pro', 'iPhone16,2' => 'iPhone 15 Pro Max',
            'iPhone17,3' => 'iPhone 16', 'iPhone17,4' => 'iPhone 16 Plus', 'iPhone17,1' => 'iPhone 16 Pro', 'iPhone17,2' => 'iPhone 16 Pro Max',
            'iPhone14,6' => 'iPhone SE (3e gen)', 'iPhone12,8' => 'iPhone SE (2e gen)',
            'iPad13,4' => 'iPad Pro 11" (3e gen)', 'iPad13,5' => 'iPad Pro 11" (3e gen)', 'iPad13,6' => 'iPad Pro 11" (3e gen)', 'iPad13,7' => 'iPad Pro 11" (3e gen)',
            'iPad13,8' => 'iPad Pro 12.9" (5e gen)', 'iPad13,9' => 'iPad Pro 12.9" (5e gen)', 'iPad13,10' => 'iPad Pro 12.9" (5e gen)', 'iPad13,11' => 'iPad Pro 12.9" (5e gen)',
            'iPad14,3' => 'iPad Pro 11" (4e gen)', 'iPad14,4' => 'iPad Pro 11" (4e gen)', 'iPad14,5' => 'iPad Pro 12.9" (6e gen)', 'iPad14,6' => 'iPad Pro 12.9" (6e gen)',
            'iPad13,1' => 'iPad Air (4e gen)', 'iPad13,2' => 'iPad Air (4e gen)', 'iPad13,16' => 'iPad Air (5e gen)', 'iPad13,17' => 'iPad Air (5e gen)',
            'iPad14,1' => 'iPad mini (6e gen)', 'iPad14,2' => 'iPad mini (6e gen)',
            'iPad12,1' => 'iPad (9e gen)', 'iPad12,2' => 'iPad (9e gen)', 'iPad13,18' => 'iPad (10e gen)', 'iPad13,19' => 'iPad (10e gen)',
            'iPad16,3' => 'iPad Pro 11" (M4)', 'iPad16,4' => 'iPad Pro 11" (M4)', 'iPad16,5' => 'iPad Pro 13" (M4)', 'iPad16,6' => 'iPad Pro 13" (M4)',
            'x86_64' => 'Simulator', 'arm64' => 'Simulator',
        ];

        return $names[$model] ?? $model;
    }
}
