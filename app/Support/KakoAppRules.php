<?php

namespace App\Support;

/**
 * Pure rekenregels voor de KAKO app-modus (zie docs/kako-app-modus.md): instellingen
 * met veilige standaardwaarden, afstand/koers, QR/NFC-tokens en het uitsplitsen van een
 * routecode ("1A") in de twee route-delen die de deelnemer loopt.
 *
 * Geen database, geen request — zodat de regels los te testen zijn.
 */
final class KakoAppRules
{
    /** Versie van de toestemmingstekst voor positie-delen; nieuwe tekst = nieuwe toestemming. */
    public const CONSENT_VERSION = 1;

    /** Een venster is hooguit zo lang: een vergeten venster mag niet eindeloos volgen. */
    public const MAX_WINDOW_HOURS = 12;

    /** Uren na het einde van het venster waarin offline gebufferde samples nog binnenkomen. */
    public const LATE_UPLOAD_HOURS = 48;

    public const RESTRICTION_KEYS = ['navigation', 'tools', 'locationSharing', 'sidebar', 'routemap', 'editing'];

    /** Instellingen uit `settings.appMode`, opgeschoond en met standaardwaarden (zie het ontwerp). */
    public static function config(?array $settings): array
    {
        $app = (array) ($settings['appMode'] ?? []);
        $tracking = (array) ($app['tracking'] ?? []);
        $checkin = (array) ($app['checkin'] ?? []);

        $mode = $tracking['mode'] ?? 'optional';
        $methods = array_values(array_intersect((array) ($checkin['methods'] ?? ['manual', 'qr', 'nfc']), ['manual', 'qr', 'nfc']));
        $maxAttempts = isset($checkin['maxAttempts']) && is_numeric($checkin['maxAttempts']) && (int) $checkin['maxAttempts'] > 0
            ? min(99, (int) $checkin['maxAttempts'])
            : null;

        return [
            'tracking' => [
                'mode' => in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'optional',
                'interval_s' => self::clamp($tracking['intervalS'] ?? 15, 5, 120),
                'retention_days' => self::clamp($tracking['retentionDays'] ?? 30, 7, 90),
            ],
            'checkin' => [
                'methods' => $methods ?: ['manual'],
                'verification' => ($checkin['verification'] ?? 'radius') === 'record' ? 'record' : 'radius',
                'radius_m' => self::clamp($checkin['radiusM'] ?? 30, 5, 200),
                'max_attempts' => $maxAttempts,
            ],
            'leave_allowed' => (bool) ($app['leaveAllowed'] ?? true),
            'tag_version' => max(1, (int) ($app['tagVersion'] ?? 1)),
        ];
    }

    /** `true` = toegestaan. Ontbrekende sleutel = toegestaan (oude opdrachten). */
    public static function restrictions(?array $settings): array
    {
        $stored = (array) ($settings['restrictions'] ?? []);
        $out = [];
        foreach (self::RESTRICTION_KEYS as $key) {
            $out[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : true;
        }

        return $out;
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) (is_numeric($value) ? $value : $min)));
    }

    // ── Geometrie ──────────────────────────────────────────────────────

    public static function distanceM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371008.8;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Beginkoers van punt 1 naar punt 2 in hele graden 0–359. */
    public static function bearingDeg(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lon2 - $lon1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        $deg = fmod(rad2deg(atan2($y, $x)) + 360, 360);

        return (int) (round($deg) % 360);
    }

    /** Lengte van een lijn door [[lat, lon], …] in meters. */
    public static function polylineM(array $points): float
    {
        $sum = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $sum += self::distanceM($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        return $sum;
    }

    // ── Check-in bewijs ────────────────────────────────────────────────

    /**
     * Token voor QR/NFC bij één prikker. Ophogen van `tagVersion` maakt alle gedrukte
     * codes ongeldig. Afgeleid van de app-sleutel: er hoeft geen geheim per opdracht te worden bewaard.
     */
    public static function tagToken(string $assignmentId, string $postId, int $tagVersion): string
    {
        $mac = hash_hmac('sha256', "kako:{$assignmentId}:{$postId}:{$tagVersion}", (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
    }

    public static function tagValid(string $assignmentId, string $postId, int $tagVersion, ?string $code): bool
    {
        return is_string($code) && $code !== '' && hash_equals(self::tagToken($assignmentId, $postId, $tagVersion), $code);
    }

    /** Is een check-in op `$distanceM` (met GPS-onnauwkeurigheid) binnen de straal? */
    public static function withinRadius(float $distanceM, ?float $accuracyM, int $radiusM): bool
    {
        return max(0.0, $distanceM - min(max(0.0, (float) $accuracyM), 25.0)) <= $radiusM;
    }

    // ── Route ──────────────────────────────────────────────────────────

    /**
     * "1A" → koers 1 dan paden A; "A1" → paden A dan koers 1. `$order` (course_first | paths_first)
     * kan een afwijking vastleggen. Onbekend formaat → de hele code als één deel.
     *
     * @return list<array{code:string, kind:string}>  in loopvolgorde
     */
    public static function routeParts(?string $routeCode, ?string $order = null): array
    {
        $code = trim((string) $routeCode);
        if ($code === '') {
            return [];
        }
        if (preg_match('/^(\d+)([A-Za-z]+)$/', $code, $m)) {
            $parts = [['code' => $m[1], 'kind' => 'course'], ['code' => strtoupper($m[2]), 'kind' => 'paths']];
        } elseif (preg_match('/^([A-Za-z]+)(\d+)$/', $code, $m)) {
            $parts = [['code' => strtoupper($m[1]), 'kind' => 'paths'], ['code' => $m[2], 'kind' => 'course']];
        } else {
            return [['code' => $code, 'kind' => ctype_digit($code) ? 'course' : 'paths']];
        }
        // De code zelf bepaalt de volgorde ("1A" vs "A1"); `order` is alleen een vangnet als de twee afwijken.
        if ($order === 'paths_first' && $parts[0]['kind'] === 'course') {
            $parts = array_reverse($parts);
        } elseif ($order === 'course_first' && $parts[0]['kind'] === 'paths') {
            $parts = array_reverse($parts);
        }

        return $parts;
    }

    /**
     * Benen van één route (lijst `locations` van een route_map): per been label, omschrijving,
     * koers en afstand. Bevat bewust géén coördinaten (de deelnemer heeft ze ook niet op papier).
     *
     * @return list<array<string,mixed>>
     */
    public static function legs(array $locations, string $kind, bool $withDescription): array
    {
        $legs = [];
        $locations = array_values($locations);
        for ($i = 1; $i < count($locations); $i++) {
            $from = $locations[$i - 1];
            $to = $locations[$i];
            if (! isset($from['lat'], $from['lon'], $to['lat'], $to['lon'])) {
                continue;
            }
            $via = array_values(array_filter((array) ($from['via'] ?? []), fn ($v) => isset($v['lat'], $v['lon'])));
            $line = [[(float) $from['lat'], (float) $from['lon']]];
            foreach ($via as $v) {
                $line[] = [(float) $v['lat'], (float) $v['lon']];
            }
            $line[] = [(float) $to['lat'], (float) $to['lon']];

            $isLast = $i === count($locations) - 1;
            $first = $line[1];
            $legs[] = [
                'index' => $i,
                'post_id' => (string) ($to['id'] ?? ''),
                'role' => $isLast ? 'finish' : 'post',
                'label' => (string) ($to['checkpoint']['label'] ?? $to['title'] ?? ('Prikker ' . $i)),
                'description' => $withDescription ? ($to['description'] ?? null) : null,
                // Koersroute: hemelsbreed (bij een tussenpunt: de koers van het eerste stuk). Padenroute: geen koers.
                'bearing_deg' => $kind === 'course' ? self::bearingDeg($line[0][0], $line[0][1], $first[0], $first[1]) : null,
                'distance_m' => (int) round(self::polylineM($line)),
            ];
        }

        return $legs;
    }
}
