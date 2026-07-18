<?php

namespace App\Console\Commands;

use App\Http\Controllers\TerrainTileController;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ruimt de terrain-tile-cache op (zie TerrainTileController): tiles ouder dan
 * de TTL weg, lege mappen erachteraan. Dagelijks gepland in routes/console.php.
 */
class PruneTerrainTiles extends Command
{
    protected $signature = 'tiles:prune-terrain {--days= : TTL in dagen (default: controller-TTL)}';

    protected $description = 'Verwijder gecachte terrain-RGB-tiles ouder dan de cache-TTL';

    public function handle(): int
    {
        $root = storage_path('app/tiles/terrain');
        if (! is_dir($root)) {
            $this->info('Geen terrain-tile-cache aanwezig.');
            return self::SUCCESS;
        }

        $days   = (int) ($this->option('days') ?: TerrainTileController::CACHE_TTL_DAYS);
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                if ($entry->getMTime() < $cutoff && @unlink($entry->getPathname())) {
                    $removed++;
                }
            } elseif (! (new FilesystemIterator($entry->getPathname()))->valid()) {
                @rmdir($entry->getPathname()); // lege z/x-map
            }
        }

        $this->info("{$removed} verlopen terrain-tiles verwijderd (> {$days} dagen).");
        return self::SUCCESS;
    }
}
