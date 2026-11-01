<?php

namespace App\Services\RouteImport;

use App\Models\TrailRoute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RouteImporter
{
    /** `osm`: een in OpenStreetMap gemapte route; `generated`: een door MilMap over OSM-paden gelegd rondje. */
    private const ALLOWED_SOURCES = ['osm', 'generated'];

    private const ALLOWED_TYPES = ['wandelen', 'fietsen', 'mtb', 'hardlopen'];

    public function __construct(
        private GpxParser $parser,
        private LineSimplifier $simplifier,
    ) {
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    public function import(array $item, string $baseDir, string $disk, float $tolerance, bool $dryRun): string
    {
        $data = $this->validate($item);
        $gpxPath = $this->resolveGpxPath($baseDir, $data['gpx_file']);
        $segments = $this->parser->parse($gpxPath);

        $geometry = [
            'type' => 'MultiLineString',
            'coordinates' => array_map(fn ($segment) => $this->simplifier->simplify($segment, $tolerance), $segments),
        ];
        [$minLon, $minLat, $maxLon, $maxLat] = $this->bounds($segments);

        $existing = TrailRoute::where('source', $data['source'])->where('source_id', $data['source_id'])->first();
        $storagePath = "routes/{$data['type']}/" . ($data['province'] ? "{$data['province']}/" : '') . basename($gpxPath);

        $attributes = [
            'slug' => $existing?->slug ?? $this->uniqueSlug($data['slug']),
            'name' => $data['name'],
            'type' => $data['type'],
            'country' => $data['country'],
            'province' => $data['province'],
            'distance_km' => $data['distance_km'],
            'is_loop' => $data['is_loop'],
            'segments' => count($segments),
            'start_lat' => $segments[0][0][1],
            'start_lon' => $segments[0][0][0],
            'min_lat' => $minLat,
            'min_lon' => $minLon,
            'max_lat' => $maxLat,
            'max_lon' => $maxLon,
            'geometry' => $geometry,
            'gpx_path' => $storagePath,
            'network' => $data['network'],
            'operator' => $data['operator'],
            'website' => $data['website'],
            'source_url' => $data['source_url'],
            'attribution' => $data['attribution'],
            'is_published' => true,
        ];

        $model = $existing ?? new TrailRoute(['source' => $data['source'], 'source_id' => $data['source_id']]);
        $model->fill($attributes);

        if ($existing && ! $model->isDirty()) {
            return 'unchanged';
        }

        if (! $dryRun) {
            Storage::disk($disk)->put($storagePath, file_get_contents($gpxPath));
            $model->save();
        }

        return $existing ? 'updated' : 'created';
    }

    private function validate(array $item): array
    {
        $type = $item['type'] ?? null;
        $source = $item['source'] ?? 'osm';
        // Een OSM-route wordt geïdentificeerd door zijn relatie; een gegenereerd rondje door het
        // stabiele nummer dat milmap_run_loops.py per gebied+lengte uitdeelt.
        $sourceId = $source === 'osm' ? ($item['osm_relation'] ?? null) : ($item['source_id'] ?? null);

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('Onbekend routetype');
        }
        if (! in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new RuntimeException('Onbekende bron');
        }
        if (! is_int($sourceId) || $sourceId <= 0) {
            throw new RuntimeException($source === 'osm' ? 'Ongeldige OSM-relatie' : 'Ongeldig bronnummer');
        }
        if (empty($item['name']) || empty($item['gpx_file'])) {
            throw new RuntimeException('Naam of GPX-bestand ontbreekt');
        }

        if ($source === 'osm') {
            $sourceUrl = "https://www.openstreetmap.org/relation/{$sourceId}";
            $attribution = '© OpenStreetMap contributors, ODbL 1.0';
        } else {
            $sourceUrl = filter_var($item['source_url'] ?? null, FILTER_VALIDATE_URL);
            $attribution = trim(strip_tags((string) ($item['attribution'] ?? '')));
            if (! $sourceUrl || ! preg_match('#^https://#i', $sourceUrl)) {
                throw new RuntimeException('Bron-URL ontbreekt of is niet https');
            }
            // ODbL: ook een afgeleid rondje houdt de OSM-bronvermelding.
            if (! str_contains($attribution, 'OpenStreetMap')) {
                throw new RuntimeException('Bronvermelding moet OpenStreetMap noemen');
            }
            $sourceUrl = Str::limit($sourceUrl, 250, '');
            $attribution = Str::limit($attribution, 250, '');
        }

        $province = isset($item['province']) ? Str::slug((string) $item['province']) : null;
        $website = filter_var($item['website'] ?? null, FILTER_VALIDATE_URL);

        return [
            'source' => $source,
            'source_id' => $sourceId,
            'source_url' => $sourceUrl,
            'attribution' => $attribution,
            'type' => $type,
            'name' => Str::limit(strip_tags((string) $item['name']), 250, ''),
            'slug' => Str::slug($item['slug'] ?? $item['name']) ?: "route-{$sourceId}",
            'gpx_file' => (string) $item['gpx_file'],
            'country' => strtoupper(substr((string) ($item['country'] ?? 'NL'), 0, 2)),
            'province' => $province ?: null,
            'distance_km' => max(0, min(99999, (float) ($item['distance_km'] ?? 0))),
            'is_loop' => (bool) ($item['is_loop'] ?? false),
            'network' => isset($item['network']) ? Str::limit(strip_tags((string) $item['network']), 20, '') : null,
            'operator' => isset($item['operator']) ? Str::limit(strip_tags((string) $item['operator']), 250, '') : null,
            'website' => $website && preg_match('#^https?://#i', $website) ? Str::limit($website, 250, '') : null,
        ];
    }

    private function resolveGpxPath(string $baseDir, string $relative): string
    {
        $base = realpath($baseDir);
        $path = realpath($baseDir . DIRECTORY_SEPARATOR . $relative);

        if (! $base || ! $path || ! str_starts_with($path, $base . DIRECTORY_SEPARATOR) || ! str_ends_with(strtolower($path), '.gpx')) {
            throw new RuntimeException('GPX-pad ongeldig of buiten importmap');
        }

        return $path;
    }

    private function bounds(array $segments): array
    {
        $lons = $lats = [];
        foreach ($segments as $segment) {
            foreach ($segment as [$lon, $lat]) {
                $lons[] = $lon;
                $lats[] = $lat;
            }
        }

        return [min($lons), min($lats), max($lons), max($lats)];
    }

    private function uniqueSlug(string $slug): string
    {
        $slug = Str::limit($slug, 100, '');
        $candidate = $slug;
        $i = 2;

        while (TrailRoute::where('slug', $candidate)->exists()) {
            $candidate = "{$slug}-{$i}";
            $i++;
        }

        return $candidate;
    }
}
