<?php

namespace App\Console\Commands;

use App\Http\Controllers\TerrainTileController;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Vult de terrain-tile-cache (zie TerrainTileController) vooraf voor een
 * gebied, parallel. Zo hoeft de eerste gebruiker die de bergterrein-overlay
 * uitgezoomd opent niet te wachten op tientallen losse Mapbox-roundtrips.
 *
 *   php artisan tiles:warm-terrain                       # Europa, z6–z8
 *   php artisan tiles:warm-terrain --bbox=5,43,16,48 --zmin=6 --zmax=10
 */
class WarmTerrainTiles extends Command
{
    protected $signature = 'tiles:warm-terrain
        {--bbox=-11,35,32,71 : minLon,minLat,maxLon,maxLat}
        {--zmin=6}
        {--zmax=8}
        {--concurrency=8}';

    protected $description = 'Haal terrain-RGB-tiles vooraf op voor een gebied (parallel)';

    public function handle(): int
    {
        [$w, $s, $e, $n] = array_map('floatval', explode(',', (string) $this->option('bbox')));
        $zmin = (int) $this->option('zmin');
        $zmax = min((int) $this->option('zmax'), TerrainTileController::MAX_ZOOM);
        $conc = max(1, (int) $this->option('concurrency'));
        $token = config('services.mapbox.token');
        $ttl = now()->subDays(TerrainTileController::CACHE_TTL_DAYS)->getTimestamp();

        $todo = [];
        for ($z = $zmin; $z <= $zmax; $z++) {
            [$x0, $y1] = $this->tile($w, $s, $z);
            [$x1, $y0] = $this->tile($e, $n, $z);
            for ($x = $x0; $x <= $x1; $x++) {
                for ($y = $y0; $y <= $y1; $y++) {
                    $png = storage_path("app/tiles/terrain/{$z}/{$x}/{$y}.png");
                    if (! (is_file($png) && filemtime($png) > $ttl)) {
                        $todo[] = [$z, $x, $y];
                    }
                }
            }
        }

        $this->info(count($todo) . ' tiles op te halen.');
        $bar = $this->output->createProgressBar(count($todo));
        $ok = 0;

        foreach (array_chunk($todo, $conc) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($t) => $pool->timeout(20)->get(
                    "https://api.mapbox.com/v4/mapbox.terrain-rgb/{$t[0]}/{$t[1]}/{$t[2]}.pngraw",
                    ['access_token' => $token]
                ),
                $chunk
            ));

            foreach ($chunk as $i => [$z, $x, $y]) {
                $r = $responses[$i] ?? null;
                if ($r instanceof \Illuminate\Http\Client\Response && $r->successful() && $r->body() !== '') {
                    $dir = storage_path("app/tiles/terrain/{$z}/{$x}");
                    File::ensureDirectoryExists($dir);
                    File::put("{$dir}/{$y}.png", $r->body());
                    $ok++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("{$ok} van " . count($todo) . ' tiles opgeslagen.');

        return self::SUCCESS;
    }

    /** lon/lat → tile-index (Web Mercator, XYZ). */
    private function tile(float $lon, float $lat, int $z): array
    {
        $n = 2 ** $z;
        $x = (int) floor(($lon + 180) / 360 * $n);
        $lat = max(-85.0511, min(85.0511, $lat));
        $y = (int) floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n);

        return [max(0, min($n - 1, $x)), max(0, min($n - 1, $y))];
    }
}
