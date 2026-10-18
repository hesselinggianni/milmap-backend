<?php

namespace App\Http\Controllers;

use App\Services\ApiUsageMeter;
use App\Support\TileWebp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class MapboxOverviewTileController extends Controller
{
    public const STYLES = ['outdoors-v12', 'streets-v11', 'satellite-v9', 'satellite-streets-v11'];
    public const TTL_DAYS = 30;
    /** Hoogste zoom op de eigen server; alles daarboven loopt rechtstreeks via Mapbox. */
    public const MAX_ZOOM = 6;

    public static function cachePath(string $style, string $language, int $z, int $x, int $y): string
    {
        return storage_path("app/tiles/mapbox-overview/{$style}/{$language}/{$z}/{$x}/{$y}.webp");
    }

    public function show(Request $request, string $style, string $language, int $z, int $x, int $y)
    {
        abort_unless(in_array($style, self::STYLES, true)
            && $language === 'nl'
            && $z >= 0 && $z <= self::MAX_ZOOM && $x >= 0 && $y >= 0 && $x < 2 ** $z && $y < 2 ** $z, 404);

        $path = self::cachePath($style, $language, $z, $x, $y);
        if (! is_file($path) || filemtime($path) <= now()->subDays(self::TTL_DAYS)->getTimestamp()) {
            if (! config('services.mapbox.token') || ! TileWebp::available()) {
                return response()->noContent(503);
            }
            try {
                $response = ApiUsageMeter::measure('mapbox', 'tiles', fn () => Http::timeout(20)->get(
                    "https://api.mapbox.com/styles/v1/mapbox/{$style}/tiles/512/{$z}/{$x}/{$y}",
                    ['access_token' => config('services.mapbox.token'), 'language' => $language]
                ));
                $image = $response->successful() ? TileWebp::encode($response->body(), 90) : null;
                if ($image === null) {
                    return response()->noContent(502);
                }
                File::ensureDirectoryExists(dirname($path));
                $temporary = tempnam(dirname($path), '.tile-');
                try {
                    File::put($temporary, $image);
                    chmod($temporary, 0644);
                    rename($temporary, $path);
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            } catch (Throwable) {
                return response()->noContent(502);
            }
        }

        return response()->file($path, $this->tileHeaders(filemtime($path)));
    }

    private function tileHeaders(int $fetchedAt): array
    {
        $remaining = max(0, $fetchedAt + self::TTL_DAYS * 86400 - time());
        return [
            'Content-Type' => 'image/webp',
            'Cache-Control' => "public, max-age={$remaining}",
            'Access-Control-Allow-Origin' => '*',
        ];
    }
}
