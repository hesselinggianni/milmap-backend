<?php

namespace App\Console\Commands;

use App\Http\Controllers\PdokTileController;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ruimt de PDOK-tegelcache op (zie PdokTileController): tegels ouder dan de
 * TTL weg, lege mappen erachteraan. Dagelijks gepland in routes/console.php.
 */
class PrunePdokTiles extends Command
{
    protected $signature = 'tiles:prune-pdok {--days= : bewaartijd in dagen (default: PdokTileController::PRUNE_AFTER_DAYS)}';

    protected $description = 'Verwijder gecachte PDOK-tegels (BRT/TOPraster) die ruim ouder zijn dan de cache-TTL';

    public function handle(): int
    {
        $root = storage_path('app/tiles/pdok');
        if (! is_dir($root)) {
            $this->info('Geen PDOK-tegelcache aanwezig.');
            return self::SUCCESS;
        }

        $days   = $this->option('days') !== null ? (int) $this->option('days') : PdokTileController::PRUNE_AFTER_DAYS;
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if (str_starts_with($entry->getPathname(), $root . '/top50/')) {
                continue; // Permanente kaartkopie, geen tijdelijke proxycache.
            }
            if ($entry->isFile()) {
                if ($entry->getMTime() < $cutoff && @unlink($entry->getPathname())) {
                    $removed++;
                }
            } elseif (! (new FilesystemIterator($entry->getPathname()))->valid()) {
                @rmdir($entry->getPathname()); // lege laag/z/x-map
            }
        }

        $this->info("{$removed} verlopen PDOK-tegels verwijderd (> {$days} dagen).");
        return self::SUCCESS;
    }
}
