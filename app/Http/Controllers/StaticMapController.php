<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cachende proxy voor de Mapbox Static Images API.
 *
 * Gebruikt door kaartthumbnails/share-kaartjes (instellingen-themakiezer,
 * activiteit-kaartje, routekaart-omslag, zoek-preview): stuk voor stuk
 * afbeeldingen met een vast lon/lat/zoom/stijl (of een track die na opname
 * toch niet meer verandert). Zonder cache haalt elke paginabezoek — of zelfs
 * elke toets in de zoekbalk, zie SearchOverlay's live preview — dezelfde
 * afbeelding opnieuw en gefactureerd bij Mapbox. Deze proxy bewaart 'm op
 * schijf (zelfde opzet als TerrainTileController) zodat dat maar één keer
 * per cache-venster gebeurt, voor alle gebruikers samen.
 *
 *   GET /api/v1/static-map?path=styles/v1/mapbox/outdoors-v12/static/...
 *
 * `path` is alles wat normaal na "https://api.mapbox.com/" zou staan
 * (inclusief eventuele querystring als padding/@2x) — de frontend bouwt 'm
 * met dezelfde helpers als voorheen, alleen het `access_token` laten we
 * altijd zelf (server-side) aanhangen; een client-token in `path` wordt
 * genegeerd.
 */
class StaticMapController extends Controller
{
    public const CACHE_TTL_DAYS = 30;

    public function show(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:8000'],
        ]);

        $path = ltrim($data['path'], '/');

        // Alleen Mapbox' Static Images API toestaan — geen open doorgeefluik
        // naar een willekeurige host of ander Mapbox-endpoint.
        if (!preg_match('#^styles/v1/mapbox/[a-zA-Z0-9\-]+/static/#', $path)) {
            return response()->json(['message' => 'Ongeldig pad.'], 422);
        }

        $key  = sha1($path);
        $dir  = storage_path('app/tiles/static/' . substr($key, 0, 2));
        $file = "{$dir}/{$key}.png";

        if (is_file($file) && filemtime($file) > now()->subDays(self::CACHE_TTL_DAYS)->getTimestamp()) {
            return $this->fileResponse($file);
        }

        // Een evt. door de client meegestuurde access_token nooit gebruiken —
        // altijd het eigen server-side token aanhangen.
        $schoon = preg_replace('/([?&])access_token=[^&]*&?/', '$1', $path);
        $schoon = rtrim($schoon, '?&');
        $sep    = str_contains($schoon, '?') ? '&' : '?';
        $url    = "https://api.mapbox.com/{$schoon}{$sep}access_token=" . config('services.mapbox.token');

        try {
            $resp = Http::timeout(12)->get($url);
        } catch (Throwable) {
            return is_file($file) ? $this->fileResponse($file) : response()->noContent(502);
        }

        if ($resp->successful() && $resp->body() !== '') {
            File::ensureDirectoryExists($dir);
            File::put($file, $resp->body());
            return $this->fileResponse($file);
        }

        return is_file($file) ? $this->fileResponse($file) : response()->noContent($resp->status() ?: 502);
    }

    private function fileResponse(string $path)
    {
        return response()->file($path, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=' . (self::CACHE_TTL_DAYS * 86400) . ', immutable',
        ]);
    }
}
