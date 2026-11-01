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

    /** KAKO-miniaturen (Top50PreviewController): publiek, elke bbox-variant is een eigen bestand. */
    private const PREVIEW_TTL_DAYS = 30;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: StaticMapController::CACHE_TTL_DAYS);
        $removed = $this->prune(storage_path('app/tiles/static'), $days)
            + $this->prune(storage_path('app/tiles/top50-preview'), self::PREVIEW_TTL_DAYS);

        $this->info("{$removed} verlopen static-map-/preview-afbeeldingen verwijderd.");
        return self::SUCCESS;
    }

    private function prune(string $root, int $days): int
    {
        if (! is_dir($root)) {
            return 0;
        }

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

        return $removed;
    }
}
