<?php

namespace Tests\Unit;

use App\Http\Controllers\PdokTileController;
use App\Support\RdGridStripper;
use PHPUnit\Framework\TestCase;

/**
 * Het gedrukte RD-kilometerraster van de TOPraster-tegels wordt uit de tegel gewist, zodat alleen
 * ons eigen MGRS-raster overblijft.
 */
class RdGridStripperTest extends TestCase
{
    /** Referentiewaarden uit proj4js met de officiële EPSG:28992-parameters: [lon, lat, RD x, RD y]. */
    private const VECTORS = [
        [5.38720621, 52.1551744, 155000.185, 463000.234],
        [5.1214, 52.0907, 136783.726, 455860.139],
        [4.9, 52.37, 121819.365, 487013.762],
        [6.57, 53.22, 234002.373, 582136.018],
        [5.69, 50.85, 176325.222, 317840.150],
        [3.6, 51.5, 30911.439, 391629.927],
        [7.05, 52.22, 268619.813, 471515.666],
        [4.76, 52.96, 112854.264, 552736.069],
    ];

    public function test_wgs84_to_rd_matches_the_reference_transform(): void
    {
        foreach (self::VECTORS as [$lon, $lat, $x, $y]) {
            [$rx, $ry] = RdGridStripper::lonLatToRd($lon, $lat);
            $this->assertEqualsWithDelta($x, $rx, 0.05, "RD x voor {$lon},{$lat}");
            $this->assertEqualsWithDelta($y, $ry, 0.05, "RD y voor {$lon},{$lat}");
        }
    }

    public function test_the_tower_of_amersfoort_is_the_origin_of_the_rd_grid(): void
    {
        // De Onze Lieve Vrouwetoren ligt per definitie op (155 000 | 463 000); de 7-parameter-
        // benadering zit er binnen een halve meter naast.
        [$x, $y] = RdGridStripper::lonLatToRd(5.38720621, 52.1551744);
        $this->assertEqualsWithDelta(155000.0, $x, 0.5);
        $this->assertEqualsWithDelta(463000.0, $y, 0.5);
    }

    public function test_predicts_lines_one_kilometre_apart_in_a_tile(): void
    {
        [$z, $x, $y] = $this->utrechtTile(14);
        $lines = RdGridStripper::predictLines(PdokTileController::tileBboxMercator($z, $x, $y), 256, 256);

        $vertical = array_values(array_filter($lines, fn ($l) => $l['axis'] === 'v'));
        $this->assertNotEmpty($vertical);
        usort($vertical, fn ($a, $b) => $a['a0'] <=> $b['a0']);
        $this->assertGreaterThan(1, count($vertical));

        // 1 km op de grond = 1000 / (tegelbreedte op de grond / 256) px; een z14-tegel is ≈2,4 km breed op NL-breedte.
        $groundWidth = (PdokTileController::tileBboxMercator($z, $x, $y)[2] - PdokTileController::tileBboxMercator($z, $x, $y)[0]) * cos(deg2rad(52.1));
        $expected = 1000 / ($groundWidth / 256);
        $gap = $vertical[1]['a0'] - $vertical[0]['a0'];
        $this->assertEqualsWithDelta($expected, $gap, $expected * 0.03);
    }

    public function test_erases_a_printed_line_even_when_the_prediction_is_off_by_a_few_pixels(): void
    {
        [$z, $x, $y] = $this->utrechtTile(14);
        $bbox = PdokTileController::tileBboxMercator($z, $x, $y);
        $line = $this->firstInsideLine($bbox, 'v');

        // Achtergrond met een "weg" (wit blok) die de lijn kruist, en het gedrukte raster 1,6 px verderop dan voorspeld.
        $im = $this->canvas(180);
        imagefilledrectangle($im, 0, 100, 255, 112, imagecolorallocate($im, 250, 250, 250));
        $shift = 1.6;
        $rows = $this->drawLine($im, $line, $shift);
        $png = $this->png($im);

        $out = RdGridStripper::strip($png, $bbox);
        $this->assertNotSame($png, $out, 'de lijn is niet gevonden');

        $result = imagecreatefromstring($out);
        foreach ([20, 60, 140, 200] as $j) {
            $c = $rows[$j];
            for ($i = $c - 1; $i <= $c + 1; $i++) {
                $this->assertEqualsWithDelta(180, $this->gray($result, $i, $j), 3, "pixel {$i},{$j} is nog donker");
            }
        }
        // In de "weg" blijft het wit zonder grijze rest van de lijn.
        $c = $rows[106];
        $this->assertEqualsWithDelta(250, $this->gray($result, $c, 106), 3);

        // Alles verder dan 4 px van de lijn is ongemoeid.
        $changed = 0;
        $original = imagecreatefromstring($png);
        for ($j = 0; $j < 256; $j++) {
            for ($i = 0; $i < 256; $i++) {
                if (abs($i - $rows[$j]) > 4 && imagecolorat($original, $i, $j) !== imagecolorat($result, $i, $j)) {
                    $changed++;
                }
            }
        }
        $this->assertSame(0, $changed);
    }

    public function test_a_tile_without_a_grid_comes_back_unchanged(): void
    {
        [$z, $x, $y] = $this->utrechtTile(14);
        $im = $this->canvas(190);
        imagefilledrectangle($im, 30, 40, 90, 120, imagecolorallocate($im, 30, 30, 30)); // een gebouw
        $png = $this->png($im);

        $this->assertSame($png, RdGridStripper::strip($png, PdokTileController::tileBboxMercator($z, $x, $y)));
    }

    public function test_a_dark_road_that_is_not_on_a_kilometre_line_is_left_alone(): void
    {
        [$z, $x, $y] = $this->utrechtTile(14);
        $bbox = PdokTileController::tileBboxMercator($z, $x, $y);
        $line = $this->firstInsideLine($bbox, 'v');

        // Een rechte donkere lijn 40 px naast waar het raster hoort te liggen: geen raster, dus niet wissen.
        $im = $this->canvas(180);
        $this->drawLine($im, $line, 40.0);
        $png = $this->png($im);

        $this->assertSame($png, RdGridStripper::strip($png, $bbox));
    }

    // ── hulpjes ──────────────────────────────────────────────────────

    /** @return array{0: int, 1: int, 2: int} z, x, y van de tegel rond de Utrechtse Dom */
    private function utrechtTile(int $z): array
    {
        $n = 2 ** $z;
        $lon = 5.1214;
        $lat = 52.0907;
        $x = (int) floor(($lon + 180) / 360 * $n);
        $y = (int) floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n);

        return [$z, $x, $y];
    }

    /** Een voorspelde lijn die ruim in de tegel ligt (ver van de randen, zodat flanken beschikbaar zijn). */
    private function firstInsideLine(array $bbox, string $axis): array
    {
        foreach (RdGridStripper::predictLines($bbox, 256, 256) as $l) {
            if ($l['axis'] === $axis && min($l['a0'], $l['a1']) > 30 && max($l['a0'], $l['a1']) < 226) {
                return $l;
            }
        }
        $this->fail('geen rasterlijn in de testtegel');
    }

    private function canvas(int $gray)
    {
        $im = imagecreatetruecolor(256, 256);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, 255, 255, imagecolorallocate($im, $gray, $gray, $gray));

        return $im;
    }

    /** Tekent een donkere 1,5 px-lijn met anti-aliasing; geeft per rij de pixel van het lijnmidden terug. */
    private function drawLine($im, array $line, float $shift): array
    {
        $rows = [];
        for ($j = 0; $j < 256; $j++) {
            $pos = $line['a0'] + ($line['a1'] - $line['a0']) * ($j + 0.5) / 256 + $shift;
            $c = (int) floor($pos);
            $rows[$j] = $c;
            imagesetpixel($im, $c, $j, imagecolorallocate($im, 45, 45, 45));
            imagesetpixel($im, $c - 1, $j, imagecolorallocate($im, 150, 150, 150));
            imagesetpixel($im, $c + 1, $j, imagecolorallocate($im, 120, 120, 120));
        }

        return $rows;
    }

    private function png($im): string
    {
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function gray($im, int $x, int $y): float
    {
        $c = imagecolorat($im, $x, $y);

        return (($c >> 16 & 0xFF) + ($c >> 8 & 0xFF) + ($c & 0xFF)) / 3;
    }
}
