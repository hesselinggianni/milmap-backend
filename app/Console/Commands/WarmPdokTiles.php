<?php

namespace App\Console\Commands;

use App\Http\Controllers\PdokTileController;
use App\Services\ApiUsageMeter;
use App\Support\TileWebp;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Haalt de PDOK/Kadaster-kaarten naar onze eigen server (zie PdokTileController)
 * en slaat ze als WebP op.
 *
 * Zonder argumenten: de "kloon" uit config services.pdok.clone — heel Nederland
 * voor de lagen/zoomniveaus die de app echt opvraagt (±230.000 tegels, een paar
 * uur bij concurrency 4). Hervatbaar: tegels die al vers op schijf staan worden
 * overgeslagen, dus afbreken en opnieuw starten is veilig. Twee keer per maand
 * gepland in routes/console.php zodat verlopen tegels (TTL) bijgewerkt worden.
 *
 *   php artisan tiles:warm-pdok                                 # de kloon
 *   php artisan tiles:warm-pdok --dry-run                       # alleen tellen
 *   php artisan tiles:warm-pdok --layer=top25 --zoom=16         # top25 z16 erbij (±1,1 mln)
 * TOP50 wordt afzonderlijk uit de landelijke bron gebouwd: scripts/build-top50.py.
 *
 * Bewust bescheiden parallel (PDOK vraagt om "normaal gebruik"); de Referer
 * gaat mee via PdokTileController::upstreamHeaders().
 */
class WarmPdokTiles extends Command
{
    protected $signature = 'tiles:warm-pdok
        {--layer= : brt of top25 (TOP50: scripts/build-top50.py; leeg = kloon-config)}
        {--zoom= : zoomniveaus, komma-gescheiden (leeg = uit de kloon-config)}
        {--bbox=2.5,50.2,7.8,54.0 : minLon,minLat,maxLon,maxLat (standaard heel NL)}
        {--concurrency=4}
        {--dry-run : alleen tellen, niets ophalen}';

    protected $description = 'Download de PDOK-kaarten (BRT/TOPraster) naar onze server als WebP-tegels';

    public function handle(): int
    {
        $plan = $this->plan();
        if ($plan === null) {
            return self::INVALID;
        }

        [$w, $s, $e, $n] = array_map('floatval', explode(',', (string) $this->option('bbox')));
        $conc = max(1, (int) $this->option('concurrency'));
        $dry  = (bool) $this->option('dry-run');
        $ttl  = now()->subDays(PdokTileController::WARM_REFRESH_DAYS)->getTimestamp();

        $encoder = TileWebp::encoder();
        $this->line('WebP-encoder: ' . ($encoder ?? '<fg=yellow>geen — tegels blijven PNG</>')
            . ($encoder ? ' (kwaliteit ' . config('services.pdok.webp_quality') . ')' : ''));

        // ── Tellen ──
        $todo = [];
        $total = 0;
        foreach ($plan as $layer => $zooms) {
            foreach ($zooms as $z) {
                [$x0, $y1] = PdokTileController::tileIndex($w, $s, $z);
                [$x1, $y0] = PdokTileController::tileIndex($e, $n, $z);
                $inLevel = 0;
                $missing = 0;
                for ($x = $x0; $x <= $x1; $x++) {
                    for ($y = $y0; $y <= $y1; $y++) {
                        if (! PdokTileController::coversTile($z, $x, $y)) {
                            continue;
                        }
                        $inLevel++;
                        $file = PdokTileController::cachedFile(PdokTileController::cacheBase($layer, $z, $x, $y));
                        if (! ($file && filemtime($file) > $ttl)) {
                            $missing++;
                            if (! $dry) {
                                $todo[] = [$layer, $z, $x, $y];
                            }
                        }
                    }
                }
                $total += $missing;
                $this->line(sprintf('  %-6s z%-2d  %7d tegels, %7d op te halen', $layer, $z, $inLevel, $missing));
            }
        }
        $this->info("{$total} tegels op te halen.");

        if ($dry || $total === 0) {
            return self::SUCCESS;
        }

        // ── Ophalen ──
        $bar = $this->output->createProgressBar($total);
        $ok = 0;

        $minFree = (float) config('services.pdok.min_free_gb', 3) * 1024 ** 3;

        foreach (array_chunk($todo, $conc) as $chunk) {
            // Schijf bijna vol: stoppen i.p.v. door te gaan met ophalen dat PdokTileController::store() toch weigert.
            $free = @disk_free_space(storage_path('app'));
            if ($free !== false && $free < $minFree) {
                $bar->finish();
                $this->newLine();
                $this->error(sprintf('Gestopt: nog maar %.1f GB vrij (grens %.1f GB, services.pdok.min_free_gb). %d tegels opgeslagen.', $free / 1024 ** 3, $minFree / 1024 ** 3, $ok));
                return self::FAILURE;
            }

            $responses = Http::pool(fn (Pool $pool) => array_map(
                function ($t) use ($pool) {
                    [$url, $query] = PdokTileController::upstreamRequest(...$t);

                    return $pool->timeout(20)->withHeaders(PdokTileController::upstreamHeaders())->get($url, $query);
                },
                $chunk
            ));

            $chunkOk = 0;
            foreach ($chunk as $i => [$layer, $z, $x, $y]) {
                $r = $responses[$i] ?? null;
                if ($r instanceof Response && PdokTileController::isImage($r)) {
                    if (PdokTileController::store(PdokTileController::cacheBase($layer, $z, $x, $y), $r->body())) {
                        $ok++;
                        $chunkOk++;
                    }
                }
                $bar->advance();
            }
            // API-verbruik: server-side, geen gebruiker/netwerk.
            ApiUsageMeter::record('pdok', 'tiles', [
                'requests' => count($chunk), 'errors' => count($chunk) - $chunkOk, 'user_id' => 0, 'network_hash' => '',
            ]);
        }

        $bar->finish();
        $this->newLine();
        $this->info("{$ok} van {$total} tegels opgeslagen.");

        return $ok === $total ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Welke laag/zoomniveaus: uit --layer/--zoom, aangevuld uit de kloon-config.
     *
     * @return array<string, int[]>|null
     */
    private function plan(): ?array
    {
        $clone = (array) config('services.pdok.clone', []);
        $layer = (string) $this->option('layer');
        $zoomOpt = trim((string) $this->option('zoom'));

        if ($layer === 'top50') {
            $this->error('TOP50 is een permanente lokale kaart. Gebruik scripts/build-top50.py om de GeoTIFF-bron naar WebP om te zetten.');
            return null;
        }

        if ($layer === '' && $zoomOpt !== '') {
            $this->error('--zoom vereist --layer.');
            return null;
        }
        if ($layer !== '' && ! isset(PdokTileController::LAYERS[$layer])) {
            $this->error("Onbekende laag '{$layer}'. Kies uit: " . implode(', ', array_keys(PdokTileController::LAYERS)));
            return null;
        }

        $layers = $layer !== '' ? [$layer] : array_values(array_diff(array_keys($clone), ['top50']));
        $plan = [];
        foreach ($layers as $l) {
            $def = PdokTileController::LAYERS[$l];
            $zooms = $zoomOpt !== ''
                ? array_map('intval', explode(',', $zoomOpt))
                : ($clone[$l] ?? range($def['minZoom'], $def['maxZoom']));
            $zooms = array_values(array_unique(array_filter($zooms, fn ($z) => $z >= $def['minZoom'] && $z <= $def['maxZoom'])));
            sort($zooms);
            if (! $zooms) {
                $this->error("Geen geldig zoomniveau voor '{$l}' (bereik z{$def['minZoom']}–z{$def['maxZoom']}).");
                return null;
            }
            $plan[$l] = $zooms;
        }

        return $plan;
    }
}
