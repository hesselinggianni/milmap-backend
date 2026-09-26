<?php

namespace App\Console\Commands;

use App\Http\Controllers\StaticMapController;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ruimt de static-map-cache op (zie StaticMapController): afbeeldingen ouder
 * dan de TTL weg, lege mappen erachteraan. Dagelijks gepland in
 * routes/console.php, zelfde opzet als tiles:prune-terrain.
 */
class PruneStaticMapCache extends Command
{
    protected $signature = 'tiles:prune-static {--days= : TTL in dagen (default: controller-TTL)}';

    protected $description = 'Verwijder gecachte Mapbox static-map-afbeeldingen ouder dan de cache-TTL';

    public function handle(): int
    {
        $root = storage_path('app/tiles/static');
        if (! is_dir($root)) {
            $this->info('Geen static-map-cache aanwezig.');
            return self::SUCCESS;
        }

        $days   = (int) ($this->option('days') ?: StaticMapController::CACHE_TTL_DAYS);
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
                @rmdir($entry->getPathname());
            }
        }

        $this->info("{$removed} verlopen static-map-afbeeldingen verwijderd (> {$days} dagen).");
        return self::SUCCESS;
    }
}
