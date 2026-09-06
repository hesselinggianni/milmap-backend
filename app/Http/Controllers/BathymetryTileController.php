<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cachende proxy voor bathymetrie (waterdiepte) tiles.
 *
 * De waterdiepte-laag heeft geen ruwe hoogtedata zoals de bergterrein-laag
 * (Mapbox terrain-RGB); in plaats daarvan proxyt en cachet deze controller
 * kant-en-klare gekleurde WMS-tiles:
 *
 *   - EMODnet Bathymetry (hogere resolutie, alleen Europese wateren)
 *   - GEBCO (wereldwijde dekking, grovere resolutie) als fallback buiten Europa
 *
 * Elke {z}/{x}/{y} XYZ-tile wordt omgerekend naar een EPSG:3857 bbox en als
 * WMS GetMap-request doorgestuurd. Net als bij de terrain-tiles wordt het
 * resultaat op schijf gecachet (storage/app/tiles/bathy) zodat herhaald laden
 * niet steeds de upstream-dienst belast.
 *
 *   GET /api/v1/tiles/bathymetry/{z}/{x}/{y}  → image/png
 */
class BathymetryTileController extends Controller
{
    public const MAX_ZOOM = 12;
    public const CACHE_TTL_DAYS = 30;
    public const TILE_SIZE = 256;

    // Ruwe dekkingsgrens van EMODnet Bathymetry (Europese wateren, EPSG:4326).
    private const EMODNET_BOUNDS = ['minLon' => -20, 'minLat' => 30, 'maxLon' => 40, 'maxLat' => 82];

    public function show(int $z, int $x, int $y)
    {
        $side = 2 ** $z;
        if ($z > self::MAX_ZOOM || $x < 0 || $y < 0 || $x >= $side || $y >= $side) {
            return response()->json(['message' => 'Tile out of range.'], 404);
        }

        $dir  = storage_path("app/tiles/bathy/{$z}/{$x}");
        $png  = "{$dir}/{$y}.png";
        $miss = "{$dir}/{$y}.404";

        $fresh = fn (string $path, int $days) =>
            is_file($path) && filemtime($path) > now()->subDays($days)->getTimestamp();

        if ($fresh($png, self::CACHE_TTL_DAYS)) {
            return $this->tileResponse($png);
        }
        if ($fresh($miss, 1)) {
            return response()->noContent(404);
        }

        [$bboxMerc, $bboxDeg] = $this->tileBounds($z, $x, $y);
        $source = $this->pickSource($bboxDeg);

        try {
            $resp = Http::timeout(12)->get($source['base'], [
                'SERVICE' => 'WMS',
                'VERSION' => '1.3.0',
                'REQUEST' => 'GetMap',
                'LAYERS' => $source['layers'],
                'STYLES' => $source['styles'],
                'CRS' => 'EPSG:3857',
                'BBOX' => implode(',', $bboxMerc),
                'WIDTH' => self::TILE_SIZE,
                'HEIGHT' => self::TILE_SIZE,
                'FORMAT' => 'image/png',
                'TRANSPARENT' => 'TRUE',
            ]);
        } catch (Throwable) {
            return is_file($png) ? $this->tileResponse($png) : response()->noContent(502);
        }

        $contentType = $resp->header('Content-Type');
        if ($resp->successful() && str_starts_with((string) $contentType, 'image/') && $resp->body() !== '') {
            File::ensureDirectoryExists($dir);
            File::put($png, $resp->body());
            @unlink($miss);
            return $this->tileResponse($png);
        }

        // WMS geeft bij een ongeldige request een XML-foutdocument terug i.p.v.
        // een HTTP-foutcode — dat vangen we hierboven af via de Content-Type-check.
        File::ensureDirectoryExists($dir);
        File::put($miss, '');
        return response()->noContent(404);
    }

    /**
     * @return array{0: array<int, float>, 1: array{minLon: float, minLat: float, maxLon: float, maxLat: float}}
     */
    private function tileBounds(int $z, int $x, int $y): array
    {
        $n = 2 ** $z;
        $lonMin = $x / $n * 360 - 180;
        $lonMax = ($x + 1) / $n * 360 - 180;
        $latRad = fn (int $yTile) => atan(sinh(M_PI * (1 - 2 * $yTile / $n)));
        $latMax = rad2deg($latRad($y));
        $latMin = rad2deg($latRad($y + 1));

        $toMerc = fn (float $lon, float $lat) => [
            $lon * 20037508.34 / 180,
            (log(tan((90 + $lat) * M_PI / 360)) / (M_PI / 180)) * 20037508.34 / 180,
        ];
        [$xMin, $yMin] = $toMerc($lonMin, $latMin);
        [$xMax, $yMax] = $toMerc($lonMax, $latMax);

        return [
            [$xMin, $yMin, $xMax, $yMax],
            ['minLon' => $lonMin, 'minLat' => $latMin, 'maxLon' => $lonMax, 'maxLat' => $latMax],
        ];
    }

    /**
     * @param array{minLon: float, minLat: float, maxLon: float, maxLat: float} $bboxDeg
     * @return array{base: string, layers: string, styles: string}
     */
    private function pickSource(array $bboxDeg): array
    {
        $b = self::EMODNET_BOUNDS;
        $inEurope = $bboxDeg['minLon'] >= $b['minLon'] && $bboxDeg['maxLon'] <= $b['maxLon']
            && $bboxDeg['minLat'] >= $b['minLat'] && $bboxDeg['maxLat'] <= $b['maxLat'];

        if ($inEurope) {
            return [
                'base' => config('services.bathymetry.emodnet_base'),
                'layers' => 'emodnet:mean_multicolour',
                'styles' => '',
            ];
        }

        return [
            'base' => config('services.bathymetry.gebco_base'),
            'layers' => 'GEBCO_LATEST',
            'styles' => 'default',
        ];
    }

    private function tileResponse(string $path)
    {
        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=' . (self::CACHE_TTL_DAYS * 86400) . ', immutable',
        ]);
    }
}
