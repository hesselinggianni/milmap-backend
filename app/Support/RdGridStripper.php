<?php

namespace App\Support;

/**
 * Haalt het gedrukte RD-kilometerraster uit TOPraster-tegels van PDOK.
 *
 * De TOPraster-producten van het Kadaster hebben het Rijksdriehoeksraster (RD, EPSG:28992)
 * als zwarte/grijze lijnen om de kilometer ín de afbeelding gedrukt. Dat is geen UTM/MGRS: in
 * Web Mercator zijn de lijnen ≈1,9° gedraaid t.o.v. het MGRS-raster, dus een eigen MGRS-raster
 * kan er nooit mee samenvallen en twee rasters over elkaar maken de kaart onleesbaar.
 * PDOK levert geen variant zonder raster; daarom wissen we de lijnen hier, vóór de tegel
 * wordt opgeslagen.
 *
 * Werkwijze per tegel:
 *   1. Reken de vier hoeken van de tegel om naar RD en bepaal waar elke RD-kilometerlijn de
 *      tegelrand snijdt → een rechte lijn per kilometer (over één tegel is de kromming te klein
 *      om te zien).
 *   2. Zet elke lijn op de pixel vast: de omrekening is op ~1 m nauwkeurig, de lijn zelf is 1–2 px,
 *      dus we zoeken binnen ±5 px de plek waar de afbeelding het donkerst ten opzichte van de
 *      flanken is. Zonder duidelijke lijn blijft de tegel ongemoeid (geen valse treffers op wegen).
 *   3. Vervang de pixels van de lijn door een mengsel van de pixels op 3 px links/rechts ervan.
 */
final class RdGridStripper
{
    /** Afstand tussen de gedrukte lijnen (m, in RD). Alle producten drukken een kilometerraster. */
    public const SPACING = 1000.0;

    /** Zoek ±dit (px) rond de berekende positie naar de echte lijn. */
    private const SNAP_RADIUS = 5.0;

    /** Minimale gemiddelde donkerte ten opzichte van de flanken (0–255) om een lijn te erkennen. */
    private const MIN_CONTRAST = 14.0;

    /**
     * De piek moet minstens zoveel keer het gemiddelde van de omgeving zijn. In drukke stadstegels is
     * die omgeving zelf al "donker t.o.v. flanken" (wegen, gebouwranden), vandaar een bescheiden drempel;
     * de lijnpositie is al op ~1 px bekend, dus valse treffers kunnen alleen vlak naast een km-lijn.
     */
    private const MIN_PEAK_RATIO = 1.45;

    // Bessel 1841 (RD) en WGS84.
    private const BESSEL_A = 6377397.155;
    private const BESSEL_INV_F = 299.1528128;
    private const WGS_A = 6378137.0;
    private const WGS_INV_F = 298.257223563;

    /** Bessel → WGS84 (EPSG:28992): dx, dy, dz (m), rx, ry, rz (boogseconden), schaal (ppm). */
    private const TOWGS84 = [565.4171, 50.3319, 465.5524, -0.398957, 0.343988, -1.8774, 4.0725];

    private const RD_LAT0 = 52.15616055555555;
    private const RD_LON0 = 5.38763888888889;
    private const RD_K0 = 0.9999079;
    private const RD_FE = 155000.0;
    private const RD_FN = 463000.0;

    // ── Coördinaten ──────────────────────────────────────────────────

    /** Web Mercator (EPSG:3857) → RD-coördinaten [x, y] in meters. */
    public static function mercatorToRd(float $mx, float $my): array
    {
        $r = 6378137.0;
        $lon = rad2deg($mx / $r);
        $lat = rad2deg(2 * atan(exp($my / $r)) - M_PI / 2);

        return self::lonLatToRd($lon, $lat);
    }

    /** WGS84 [lon, lat] in graden → RD-coördinaten [x, y] in meters. */
    public static function lonLatToRd(float $lon, float $lat): array
    {
        [$phi, $lam] = self::wgs84ToBessel(deg2rad($lat), deg2rad($lon));

        return self::besselToRd($phi, $lam);
    }

    /** Geodetisch WGS84 → geodetisch Bessel (Helmert, positievector zoals proj4/EPSG:28992). */
    private static function wgs84ToBessel(float $phi, float $lam): array
    {
        $e2 = self::e2(self::WGS_INV_F);
        $n = self::WGS_A / sqrt(1 - $e2 * sin($phi) ** 2);
        $x = $n * cos($phi) * cos($lam);
        $y = $n * cos($phi) * sin($lam);
        $z = $n * (1 - $e2) * sin($phi);

        [$dx, $dy, $dz, $rxs, $rys, $rzs, $ppm] = self::TOWGS84;
        $sec = M_PI / 180 / 3600;
        [$rx, $ry, $rz] = [$rxs * $sec, $rys * $sec, $rzs * $sec];
        $m = 1 + $ppm / 1e6;
        $xt = ($x - $dx) / $m;
        $yt = ($y - $dy) / $m;
        $zt = ($z - $dz) / $m;
        $bx = $xt + $rz * $yt - $ry * $zt;
        $by = -$rz * $xt + $yt + $rx * $zt;
        $bz = $ry * $xt - $rx * $yt + $zt;

        $a = self::BESSEL_A;
        $e2b = self::e2(self::BESSEL_INV_F);
        $p = sqrt($bx * $bx + $by * $by);
        $phiB = atan2($bz, $p * (1 - $e2b));
        for ($i = 0; $i < 6; $i++) {
            $nb = $a / sqrt(1 - $e2b * sin($phiB) ** 2);
            $h = $p / cos($phiB) - $nb;
            $phiB = atan2($bz, $p * (1 - $e2b * $nb / ($nb + $h)));
        }

        return [$phiB, atan2($by, $bx)];
    }

    /** Oblique stereographic (EPSG-methode 9809), Bessel-ellipsoïde → RD [x, y]. */
    private static function besselToRd(float $phi, float $lam): array
    {
        static $c = null;
        if ($c === null) {
            $e2 = self::e2(self::BESSEL_INV_F);
            $e = sqrt($e2);
            $phi0 = deg2rad(self::RD_LAT0);
            $s0 = sin($phi0);
            $rho0 = self::BESSEL_A * (1 - $e2) / (1 - $e2 * $s0 * $s0) ** 1.5;
            $nu0 = self::BESSEL_A / sqrt(1 - $e2 * $s0 * $s0);
            $n = sqrt(1 + $e2 * cos($phi0) ** 4 / (1 - $e2));
            $w1 = (((1 + $s0) / (1 - $s0)) * ((1 - $e * $s0) / (1 + $e * $s0)) ** $e) ** $n;
            $sinChi00 = ($w1 - 1) / ($w1 + 1);
            $cc = ($n + $s0) * (1 - $sinChi00) / (($n - $s0) * (1 + $sinChi00));
            $w2 = $cc * $w1;
            $chi0 = asin(($w2 - 1) / ($w2 + 1));
            $c = ['e' => $e, 'n' => $n, 'c' => $cc, 'chi0' => $chi0, 'R' => sqrt($rho0 * $nu0), 'lam0' => deg2rad(self::RD_LON0)];
        }

        $sp = sin($phi);
        $w = $c['c'] * (((1 + $sp) / (1 - $sp)) * ((1 - $c['e'] * $sp) / (1 + $c['e'] * $sp)) ** $c['e']) ** $c['n'];
        $chi = asin(($w - 1) / ($w + 1));
        $dl = $c['n'] * ($lam - $c['lam0']);
        $b = 1 + sin($chi) * sin($c['chi0']) + cos($chi) * cos($c['chi0']) * cos($dl);
        $k = 2 * $c['R'] * self::RD_K0 / $b;

        return [
            self::RD_FE + $k * cos($chi) * sin($dl),
            self::RD_FN + $k * (sin($chi) * cos($c['chi0']) - cos($chi) * sin($c['chi0']) * cos($dl)),
        ];
    }

    private static function e2(float $invF): float
    {
        $f = 1 / $invF;

        return $f * (2 - $f);
    }

    // ── Positie van de lijnen in een tegel ───────────────────────────

    /**
     * De RD-kilometerlijnen in een tegel, als rechte lijnen in pixelcoördinaten (0,0 = linkerbovenhoek).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  EPSG:3857 [xMin, yMin, xMax, yMax]
     * @return list<array{axis: string, a0: float, a1: float}>  axis 'v': lijn loopt van (a0, 0) naar (a1, hoogte);
     *                                                           axis 'h': van (0, a0) naar (breedte, a1)
     */
    public static function predictLines(array $bbox, int $width, int $height, float $spacing = self::SPACING): array
    {
        [$xMin, $yMin, $xMax, $yMax] = $bbox;
        $tl = self::mercatorToRd($xMin, $yMax);
        $tr = self::mercatorToRd($xMax, $yMax);
        $bl = self::mercatorToRd($xMin, $yMin);
        $br = self::mercatorToRd($xMax, $yMin);
        $margin = 12; // px: lijnen net buiten de tegel snijden de rand al niet meer, maar de snap-zoekruimte wel
        $lines = [];

        // Verticale lijnen: RD-x = k·spacing, gesneden met de boven- en onderrand.
        $lo = min($tl[0], $bl[0]);
        $hi = max($tr[0], $br[0]);
        for ($k = (int) floor($lo / $spacing) - 1; $k <= (int) ceil($hi / $spacing) + 1; $k++) {
            $kx = $k * $spacing;
            $top = ($tr[0] - $tl[0]) != 0.0 ? ($kx - $tl[0]) / ($tr[0] - $tl[0]) * $width : null;
            $bot = ($br[0] - $bl[0]) != 0.0 ? ($kx - $bl[0]) / ($br[0] - $bl[0]) * $width : null;
            if ($top === null || $bot === null) {
                continue;
            }
            if (max($top, $bot) < -$margin || min($top, $bot) > $width + $margin) {
                continue;
            }
            $lines[] = ['axis' => 'v', 'a0' => $top, 'a1' => $bot];
        }

        // Horizontale lijnen: RD-y = k·spacing, gesneden met de linker- en rechterrand.
        $lo = min($bl[1], $br[1]);
        $hi = max($tl[1], $tr[1]);
        for ($k = (int) floor($lo / $spacing) - 1; $k <= (int) ceil($hi / $spacing) + 1; $k++) {
            $ky = $k * $spacing;
            $left = ($bl[1] - $tl[1]) != 0.0 ? ($ky - $tl[1]) / ($bl[1] - $tl[1]) * $height : null;
            $right = ($br[1] - $tr[1]) != 0.0 ? ($ky - $tr[1]) / ($br[1] - $tr[1]) * $height : null;
            if ($left === null || $right === null) {
                continue;
            }
            if (max($left, $right) < -$margin || min($left, $right) > $height + $margin) {
                continue;
            }
            $lines[] = ['axis' => 'h', 'a0' => $left, 'a1' => $right];
        }

        return $lines;
    }

    // ── Wissen ───────────────────────────────────────────────────────

    /**
     * Wist het RD-raster uit een PNG-tegel. Zonder herkenbare lijnen (of zonder GD) komt de
     * invoer ongewijzigd terug.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  EPSG:3857 [xMin, yMin, xMax, yMax]
     */
    public static function strip(string $png, array $bbox, float $spacing = self::SPACING): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $png;
        }
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            return $png;
        }

        try {
            $w = imagesx($im);
            $h = imagesy($im);
            if ($w < 64 || $h < 64) {
                return $png;
            }
            imagepalettetotruecolor($im);
            imagealphablending($im, false);
            imagesavealpha($im, true);

            $luma = self::lumaGrid($im, $w, $h);
            $removed = 0;
            foreach (self::predictLines($bbox, $w, $h, $spacing) as $line) {
                $removed += self::eraseLine($im, $luma, $w, $h, $line) ? 1 : 0;
            }
            if ($removed === 0) {
                return $png;
            }

            ob_start();
            imagepng($im, null, 6);
            $out = (string) ob_get_clean();

            return $out !== '' ? $out : $png;
        } finally {
            imagedestroy($im);
        }
    }

    /** Helderheid per pixel (0 = zwart, 255 = wit); transparante pixels tellen als wit. */
    private static function lumaGrid($im, int $w, int $h): array
    {
        $g = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $row[$x] = $a >= 100 ? 255.0 : (($c >> 16 & 0xFF) + ($c >> 8 & 0xFF) + ($c & 0xFF)) / 3;
            }
            $g[$y] = $row;
        }

        return $g;
    }

    /** Helderheid op een (mogelijk fractionele) positie langs de as, lineair tussen pixelmiddens. */
    private static function sample(array $g, string $axis, int $along, float $pos, int $w, int $h): ?float
    {
        $limit = $axis === 'v' ? $w : $h;
        $f = $pos - 0.5;
        $i = (int) floor($f);
        if ($i < 0 || $i + 1 >= $limit) {
            return null;
        }
        $t = $f - $i;
        $a = $axis === 'v' ? $g[$along][$i] : $g[$i][$along];
        $b = $axis === 'v' ? $g[$along][$i + 1] : $g[$i + 1][$along];

        return $a * (1 - $t) + $b * $t;
    }

    /** Gemiddelde donkerte van de lijn t.o.v. de flanken bij verschuiving $shift (px). */
    private static function score(array $g, string $axis, array $line, float $shift, int $w, int $h): float
    {
        $n = $axis === 'v' ? $h : $w;
        $sum = 0.0;
        $count = 0;
        for ($j = 0; $j < $n; $j++) {
            $pos = $line['a0'] + ($line['a1'] - $line['a0']) * ($j + 0.5) / $n + $shift;
            $mid = self::sample($g, $axis, $j, $pos, $w, $h);
            $lo = self::sample($g, $axis, $j, $pos - 3, $w, $h);
            $hi = self::sample($g, $axis, $j, $pos + 3, $w, $h);
            if ($mid === null || $lo === null || $hi === null) {
                continue;
            }
            $sum += max(0.0, min(255.0, ($lo + $hi) / 2 - $mid));
            $count++;
        }

        return $count >= $n * 0.5 ? $sum / $count : 0.0;
    }

    /** Zoekt de echte lijn rond de berekende positie en wist hem. @return bool of er iets gewist is */
    private static function eraseLine($im, array $g, int $w, int $h, array $line): bool
    {
        $axis = $line['axis'];
        $n = $axis === 'v' ? $h : $w;

        $scores = [];
        for ($s = -self::SNAP_RADIUS; $s <= self::SNAP_RADIUS + 1e-9; $s += 0.25) {
            $scores[(string) $s] = self::score($g, $axis, $line, $s, $w, $h);
        }
        $bestKey = array_keys($scores, max($scores))[0];
        $best = (float) $bestKey;
        $peak = $scores[$bestKey];
        // Gemiddelde van de verschuivingen ≥2 px van de piek: de "achtergrondruis" van de omgeving.
        $others = [];
        foreach ($scores as $k => $v) {
            if (abs((float) $k - $best) >= 2.0) {
                $others[] = $v;
            }
        }
        $floor = $others ? array_sum($others) / count($others) : 0.0;
        if ($peak < self::MIN_CONTRAST || $peak < self::MIN_PEAK_RATIO * max($floor, 1.0)) {
            return false;
        }

        // Het gedrukte raster is ±3 px breed (kern + anti-aliasing): wis die 3 px rond het midden en
        // neem de randen over van de eerste pixel erbuiten. Kopiëren i.p.v. mengen laat een weg of
        // gebouw dat de lijn kruist scherp, en een weg die vlak naast de lijn loopt schuift maar 1 px.
        $limit = $axis === 'v' ? $w : $h;
        $previous = null; // laatst gekozen kleur van het middelste pixel, voor continuïteit langs de lijn
        for ($j = 0; $j < $n; $j++) {
            $pos = $line['a0'] + ($line['a1'] - $line['a0']) * ($j + 0.5) / $n + $best;
            // Pixel waar het lijnmidden in valt; de echte lijn kan door afronding/anti-aliasing 1 px
            // ernaast liggen, dus volg per rij het donkerste pixel binnen ±1 px.
            $c = (int) floor($pos);
            $darkest = INF;
            $m = $c;
            for ($i = $c - 1; $i <= $c + 1; $i++) {
                if ($i < 0 || $i >= $limit) {
                    continue;
                }
                $l = $axis === 'v' ? $g[$j][$i] : $g[$i][$j];
                if ($l < $darkest) {
                    $darkest = $l;
                    $m = $i;
                }
            }
            $c = $m;
            if ($c - 2 < 0 || $c + 2 >= $limit) {
                continue;
            }
            $at = fn (int $i) => $axis === 'v' ? imagecolorat($im, $i, $j) : imagecolorat($im, $j, $i);
            $put = function (int $i, int $color) use ($im, $axis, $j): void {
                if ($axis === 'v') {
                    imagesetpixel($im, $i, $j, $color);
                } else {
                    imagesetpixel($im, $j, $i, $color);
                }
            };
            $left = $at($c - 2);
            $right = $at($c + 2);
            $put($c - 1, $left);
            $put($c + 1, $right);
            // Het middelste pixel: de kant die het best aansluit op de vorige rij (een rand die langs de
            // lijn loopt blijft zo één rand); zonder vorige rij de linkerkant.
            $middle = ($previous !== null && self::distance($right, $previous) < self::distance($left, $previous)) ? $right : $left;
            $put($c, $middle);
            $previous = $middle;
        }

        return true;
    }

    /** Eenvoudig kleurverschil (som van absolute kanaalverschillen). */
    private static function distance(int $a, int $b): int
    {
        return abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF))
            + abs((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF))
            + abs(($a & 0xFF) - ($b & 0xFF));
    }
}
