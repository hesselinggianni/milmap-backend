<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cachende proxy voor Mapbox terrain-RGB DEM-tiles.
 *
 * De bergterrein-lagen (steilheid/hellingsrichting/lawine) in de app halen hun
 * hoogtedata per tile bij Mapbox. Via deze proxy komt elke tile maar één keer
 * bij Mapbox vandaan en daarna van schijf (storage/app/tiles/terrain), wat
 * herhaald laden versnelt én Mapbox-tileverbruik over alle gebruikers deelt.
 *
 *   GET /api/v1/tiles/terrain/{z}/{x}/{y}  → image/png (terrain-RGB)
 *
 * Mapbox' voorwaarden staan tijdelijk cachen tot 30 dagen toe; daarom is de
 * cache-TTL (schijf én Cache-Control) 30 dagen en ruimt `tiles:prune-terrain`
 * dagelijks oudere tiles op. Bij een onbereikbare upstream serveren we liever
 * een verlopen tile dan niets (hoogtedata verandert feitelijk nooit).
 */
class TerrainTileController extends Controller
{
    /** Hoogste zoom waarop Mapbox terrain-RGB data heeft. */
    public const MAX_ZOOM = 14;

    /** Cache-levensduur in dagen (Mapbox ToS: tijdelijk cachen ≤ 30 dagen). */
    public const CACHE_TTL_DAYS = 30;

    public function show(int $z, int $x, int $y)
    {
        $side = 2 ** $z;
        if ($z > self::MAX_ZOOM || $x >= $side || $y >= $side) {
            return response()->json(['message' => 'Tile out of range.'], 404);
        }

        $dir  = storage_path("app/tiles/terrain/{$z}/{$x}");
        $png  = "{$dir}/{$y}.png";
        $miss = "{$dir}/{$y}.404"; // marker: upstream gaf 404, niet blijven hameren

        $fresh = fn (string $path, int $days) =>
            is_file($path) && filemtime($path) > now()->subDays($days)->getTimestamp();

        if ($fresh($png, self::CACHE_TTL_DAYS)) {
            return $this->tileResponse($png);
        }
        if ($fresh($miss, 1)) {
            return response()->noContent(404);
        }

        try {
            $resp = Http::timeout(12)->get(
                "https://api.mapbox.com/v4/mapbox.terrain-rgb/{$z}/{$x}/{$y}.pngraw",
                ['access_token' => config('services.mapbox.token')]
            );
        } catch (Throwable) {
            return is_file($png)
                ? $this->tileResponse($png) // verlopen > niets
                : response()->noContent(502);
        }

        if ($resp->successful() && $resp->body() !== '') {
            File::ensureDirectoryExists($dir);
            File::put($png, $resp->body());
            @unlink($miss);
            return $this->tileResponse($png);
        }

        if ($resp->status() === 404) {
            File::ensureDirectoryExists($dir);
            File::put($miss, '');
            return response()->noContent(404);
        }

        return is_file($png)
            ? $this->tileResponse($png)
            : response()->noContent(502);
    }

    private function tileResponse(string $path)
    {
        return response()->file($path, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=' . (self::CACHE_TTL_DAYS * 86400) . ', immutable',
        ]);
    }
}
