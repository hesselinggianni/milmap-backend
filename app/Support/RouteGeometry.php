<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Hulpfuncties voor het opslaan en terugvinden van routes: een gesnapt pad als
 * compacte "encoded polyline", een omhullende (bbox) om op locatie te zoeken,
 * en een klein hoogteprofiel met stijgen/dalen.
 *
 * Alles werkt op gewone arrays en is dus zonder database te testen. Punten zijn
 * overal [lat, lon]-paren.
 */
final class RouteGeometry
{
    /** Hoeveel punten een opgeslagen pad hoogstens krijgt (ruim genoeg voor een kaartweergave). */
    public const MAX_PATH_POINTS = 600;

    /** Hoeveel punten een opgeslagen hoogteprofiel hoogstens krijgt. */
    public const MAX_PROFILE_POINTS = 150;

    /** Hoogteverschil (m) dat minimaal nodig is om als stijgen of dalen te tellen (ruis wegfilteren). */
    public const CLIMB_THRESHOLD_M = 3.0;

    // ── Encoded polyline (Google-formaat, precisie 5) ─────────────────────

    /** @param  array<int, array{0: float|int|string, 1: float|int|string}>  $points */
    public static function encode(array $points): string
    {
        $out = '';
        $prevLat = 0;
        $prevLon = 0;

        foreach ($points as $p) {
            $lat = (int) round((float) $p[0] * 1e5);
            $lon = (int) round((float) $p[1] * 1e5);
            $out .= self::encodeNumber($lat - $prevLat) . self::encodeNumber($lon - $prevLon);
            $prevLat = $lat;
            $prevLon = $lon;
        }

        return $out;
    }

    /** @return array<int, array{0: float, 1: float}> */
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = 0;
        $len = strlen($encoded);
        $lat = 0;
        $lon = 0;

        while ($index < $len) {
            foreach ([0, 1] as $axis) {
                $shift = 0;
                $result = 0;
                do {
                    if ($index >= $len) {
                        return $points;
                    }
                    $b = ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($axis === 0) {
                    $lat += $delta;
                } else {
                    $lon += $delta;
                }
            }
            $points[] = [$lat / 1e5, $lon / 1e5];
        }

        return $points;
    }

    private static function encodeNumber(int $num): string
    {
        $num = $num < 0 ? ~($num << 1) : ($num << 1);
        $out = '';
        while ($num >= 0x20) {
            $out .= chr((0x20 | ($num & 0x1f)) + 63);
            $num >>= 5;
        }

        return $out . chr($num + 63);
    }

    // ── Vereenvoudigen ────────────────────────────────────────────────────

    /**
     * Douglas-Peucker, met de tolerantie zo gekozen dat er hooguit $max punten
     * overblijven. Begin- en eindpunt blijven altijd behouden.
     *
     * @param  array<int, array{0: float|int|string, 1: float|int|string}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    public static function simplify(array $points, int $max = self::MAX_PATH_POINTS): array
    {
        $points = array_values(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $points));
        $n = count($points);
        if ($n <= $max || $n < 3) {
            return $points;
        }

        $lat0 = array_sum(array_column($points, 0)) / $n;
        $kx = 111320.0 * cos(deg2rad($lat0));
        $ky = 110540.0;
        $xy = array_map(fn ($p) => [$p[1] * $kx, $p[0] * $ky], $points);

        $lo = 0.0;
        $hi = 5000.0;
        $best = null;
        for ($i = 0; $i < 18; $i++) {
            $mid = ($lo + $hi) / 2;
            $keep = self::douglasPeucker($xy, $mid);
            if (count($keep) > $max) {
                $lo = $mid;
            } else {
                $hi = $mid;
                $best = $keep;
            }
        }

        $keep = $best ?? self::douglasPeucker($xy, $hi);
        $out = [];
        foreach ($keep as $i) {
            $out[] = $points[$i];
        }

        // Nog te veel (zeer grillig pad): gelijkmatig uitdunnen als vangnet.
        return count($out) > $max ? self::sample($out, $max) : $out;
    }

    /** @return array<int, int> indices van de bewaarde punten, oplopend */
    private static function douglasPeucker(array $xy, float $tolerance): array
    {
        $n = count($xy);
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];

        while ($stack) {
            [$a, $b] = array_pop($stack);
            $maxDist = 0.0;
            $maxIdx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = self::distanceToSegment($xy[$i], $xy[$a], $xy[$b]);
                if ($d > $maxDist) {
                    $maxDist = $d;
                    $maxIdx = $i;
                }
            }
            if ($maxIdx !== -1 && $maxDist > $tolerance) {
                $keep[$maxIdx] = true;
                $stack[] = [$a, $maxIdx];
                $stack[] = [$maxIdx, $b];
            }
        }

        return array_keys(array_filter($keep));
    }

    private static function distanceToSegment(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 == 0.0) {
            return hypot($p[0] - $a[0], $p[1] - $a[1]);
        }
        $t = max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2));

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }

    /** Gelijkmatig uitdunnen tot hooguit $max items, eerste en laatste behouden. */
    public static function sample(array $items, int $max): array
    {
        $items = array_values($items);
        $n = count($items);
        if ($n <= $max || $max < 2) {
            return $items;
        }
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $items[(int) round($i * ($n - 1) / ($max - 1))];
        }

        return $out;
    }

    // ── Omhullende (bbox) ─────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: float|int|string, 1: float|int|string}>  $points
     * @return array{0: float, 1: float, 2: float, 3: float}|null  [minLat, minLon, maxLat, maxLon]
     */
    public static function bounds(array $points): ?array
    {
        $lats = [];
        $lons = [];
        foreach ($points as $p) {
            if (isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) {
                $lats[] = (float) $p[0];
                $lons[] = (float) $p[1];
            }
        }
        if (! $lats) {
            return null;
        }

        return [min($lats), min($lons), max($lats), max($lons)];
    }

    /** Omhullende van de waypoints plus hun buigpunten (`via`). */
    public static function boundsOfLocations(array $locations): ?array
    {
        $pts = [];
        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            if (isset($loc['lat'], $loc['lon'])) {
                $pts[] = [$loc['lat'], $loc['lon']];
            }
            foreach ((array) ($loc['via'] ?? []) as $v) {
                if (is_array($v) && isset($v['lat'], $v['lon'])) {
                    $pts[] = [$v['lat'], $v['lon']];
                }
            }
        }

        return self::bounds($pts);
    }

    /**
     * Vingerafdruk van de waypoints (lat/lon, 5 decimalen ≈ 1 m). Hiermee zien
     * we of een opgeslagen pad nog bij de waypoints hoort.
     */
    public static function locationsHash(array $locations): string
    {
        $parts = [];
        foreach ($locations as $loc) {
            if (is_array($loc) && isset($loc['lat'], $loc['lon']) && is_numeric($loc['lat']) && is_numeric($loc['lon'])) {
                $parts[] = number_format((float) $loc['lat'], 5, '.', '') . ',' . number_format((float) $loc['lon'], 5, '.', '');
            }
        }

        return md5(implode(';', $parts));
    }

    // ── Hoogteprofiel ─────────────────────────────────────────────────────

    /**
     * Profiel als [[afstand_m, hoogte_m], …]: ongeldige paren eruit, oplopend
     * op afstand, hooguit $max punten.
     *
     * @param  array<int, mixed>  $pairs
     * @return array<int, array{0: int, 1: int}>
     */
    public static function compactProfile(array $pairs, int $max = self::MAX_PROFILE_POINTS): array
    {
        $clean = [];
        foreach ($pairs as $p) {
            if (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) {
                $clean[] = [(float) $p[0], (float) $p[1]];
            }
        }
        usort($clean, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_map(
            fn ($p) => [(int) round($p[0]), (int) round($p[1])],
            self::sample($clean, $max)
        );
    }

    /**
     * Stijgen en dalen in hele meters. Alleen verschillen vanaf de drempel tellen
     * (GPS-/hoogteruis zou het totaal anders opblazen).
     *
     * @param  array<int, array{0: float|int, 1: float|int}>  $profile  [[afstand, hoogte], …]
     * @return array{0: int, 1: int}  [stijgen, dalen]
     */
    public static function ascentDescent(array $profile, float $threshold = self::CLIMB_THRESHOLD_M): array
    {
        $ref = null;
        $up = 0.0;
        $down = 0.0;
        foreach ($profile as $p) {
            $h = (float) $p[1];
            if ($ref === null) {
                $ref = $h;
                continue;
            }
            $diff = $h - $ref;
            if ($diff >= $threshold) {
                $up += $diff;
                $ref = $h;
            } elseif ($diff <= -$threshold) {
                $down += -$diff;
                $ref = $h;
            }
        }

        return [(int) round($up), (int) round($down)];
    }

    // ── Bestaande routes aanvullen ────────────────────────────────────────

    /**
     * Vult de omhullende voor routes die er nog geen hebben (uit hun waypoints).
     * Herhaalbaar: raakt alleen rijen zonder bbox. Geeft het aantal bijgewerkte rijen.
     */
    public static function backfillBounds(int $chunk = 200): int
    {
        $updated = 0;

        DB::table('route_maps')
            ->whereNull('bbox_min_lat')
            ->whereNotNull('locations')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$updated) {
                foreach ($rows as $row) {
                    $locations = json_decode((string) $row->locations, true);
                    $b = is_array($locations) ? self::boundsOfLocations($locations) : null;
                    if (! $b) {
                        continue;
                    }
                    DB::table('route_maps')->where('id', $row->id)->update([
                        'bbox_min_lat' => $b[0],
                        'bbox_min_lon' => $b[1],
                        'bbox_max_lat' => $b[2],
                        'bbox_max_lon' => $b[3],
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }
}
