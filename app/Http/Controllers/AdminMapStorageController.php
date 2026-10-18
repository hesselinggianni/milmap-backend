<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

class AdminMapStorageController extends Controller
{
    private function readJson(string $path): array
    {
        if (! is_file($path)) return [];
        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    public function index()
    {
        $root = storage_path('app/tiles');
        $manifest = $this->readJson("{$root}/mapbox-overview/manifest.json");
        $maps = [];
        foreach (['outdoors-v12' => 'Outdoors', 'streets-v11' => 'Streets', 'satellite-v9' => 'Satellite', 'satellite-streets-v11' => 'Satellite + Streets'] as $style => $name) {
            $base = $manifest['styles'][$style] ?? [];
            $counts = [];
            $complete = [];
            $bytes = (int) ($base['bytes'] ?? 0);
            if (($base['tiles'] ?? 0) === 5461 && ($manifest['zooms'] ?? []) === range(0, 6)) {
                foreach (range(0, 6) as $z) $counts[$z] = 4 ** $z;
                $complete = range(0, 6);
            }
            $maps[] = [
                'id' => $style, 'name' => $name, 'source' => 'Mapbox', 'coverage' => 'Wereldwijd',
                'format' => 'WebP', 'quality' => $manifest['quality'] ?? null,
                'quality_verified' => isset($manifest['quality']),
                'tile_size' => $manifest['tile_size'] ?? 512, 'complete_zooms' => $complete,
                'partial_zooms' => [], 'target_zooms' => range(0, 6), 'tiles' => array_sum($counts), 'bytes' => $bytes,
                'download' => null,
                'labels' => $style === 'satellite-v9' ? 'Geen tekstlabels' : 'Standaard Engels',
                'serving' => 'Zoom 0–6 (Nederlandse appinstelling): eigen server. Hogere zooms en andere talen: Mapbox-API.',
            ];
        }
        $top = $this->readJson("{$root}/pdok/top50/manifest.json");
        if ($top) {
            $maps[] = ['id' => 'top50', 'name' => 'NL TOP50', 'source' => 'PDOK / Kadaster', 'coverage' => 'Nederland',
                'format' => 'WebP', 'quality' => $top['quality'] ?? null, 'quality_verified' => isset($top['quality']),
                'tile_size' => $top['tile_size'] ?? null, 'complete_zooms' => $top['zooms'] ?? [], 'partial_zooms' => [],
                'tiles' => $top['tiles'] ?? null, 'bytes' => $top['bytes'] ?? null, 'labels' => 'Bronkaart',
                'download' => null, 'serving' => 'Permanente lokale kaart.'];
        }
        // Only inspect zoom directories, never recursively count hundreds of thousands of files on refresh.
        foreach (['pdok/brt' => 'NL BRT', 'pdok/top25' => 'NL TOP25', 'terrain' => 'Terreinhoogte (Terrain RGB)', 'bathy' => 'Waterdiepte'] as $relative => $name) {
            $directory = "{$root}/{$relative}";
            if (! is_dir($directory)) continue;
            $zooms = [];
            foreach (File::directories($directory) as $path) {
                if (preg_match('/^\d{1,2}$/D', basename($path))) $zooms[] = (int) basename($path);
            }
            sort($zooms);
            $maps[] = ['id' => $relative, 'name' => $name, 'source' => str_starts_with($relative, 'pdok/') ? 'PDOK / Kadaster' : 'Overlay-cache',
                'coverage' => 'Alleen eerder opgevraagde tegels', 'format' => $relative === 'terrain' ? 'PNG / RGB' : 'Bronformaat / WebP',
                'quality' => null, 'quality_verified' => false, 'tile_size' => null, 'complete_zooms' => [], 'partial_zooms' => $zooms,
                'tiles' => null, 'bytes' => null, 'download' => null, 'labels' => 'Bronkaart',
                'serving' => 'Cache op aanvraag; geen gegarandeerde volledige dekking.'];
        }
        return response()->json(['generated_at' => now()->toIso8601String(), 'maps' => $maps,
            'process_updated_at' => null, 'disk_free_bytes' => @disk_free_space(storage_path()) ?: null,
        ])->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
    }
}
