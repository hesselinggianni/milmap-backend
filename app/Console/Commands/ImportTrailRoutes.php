<?php

namespace App\Console\Commands;

use App\Models\TrailRoute;
use App\Services\RouteImport\RouteImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportTrailRoutes extends Command
{
    protected $signature = 'milmap:import-routes
        {path : Map met routes.json en de GPX-bestanden}
        {--disk=local : Storage-disk voor de GPX-bestanden}
        {--tolerance=0.00003 : Vereenvoudiging in graden (~3 m), 0 = uit}
        {--unpublish-missing : Zet OSM-routes die niet meer in de export staan op onzichtbaar}
        {--dry-run : Alleen controleren, niets opslaan}';

    protected $description = 'Importeer OSM-routes (wandelen/fietsen/MTB/hardlopen) uit een milmap_routes-export';

    public function handle(RouteImporter $importer): int
    {
        $baseDir = rtrim($this->argument('path'), DIRECTORY_SEPARATOR);
        $manifestPath = $baseDir . DIRECTORY_SEPARATOR . 'routes.json';

        if (! is_file($manifestPath)) {
            $this->error("routes.json niet gevonden in {$baseDir}");
            return self::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $routes = $manifest['routes'] ?? null;

        if (! is_array($routes)) {
            $this->error('routes.json heeft geen geldige "routes"-lijst');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $tolerance = max(0, (float) $this->option('tolerance'));
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $importedIds = []; // bron => [bronnummers]; de types in de export begrenzen --unpublish-missing
        $types = [];

        $bar = $this->output->createProgressBar(count($routes));
        $bar->start();

        foreach ($routes as $index => $item) {
            try {
                $result = $importer->import((array) $item, $baseDir, $this->option('disk'), $tolerance, $dryRun);
                $counts[$result]++;
                $source = $item['source'] ?? 'osm';
                $importedIds[$source][] = $source === 'osm' ? $item['osm_relation'] : $item['source_id'];
                $types[$item['type']] = true;
            } catch (Throwable $e) {
                $counts['failed']++;
                $this->newLine();
                $this->warn(sprintf('#%d %s: %s', $index, $item['name'] ?? 'onbekend', $e->getMessage()));
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($this->option('unpublish-missing') && ! $dryRun && $importedIds) {
            // Alleen binnen de bronnen en routetypes van déze export: een hardloop-export
            // laat de wandelroutes dus met rust.
            $hidden = 0;
            foreach ($importedIds as $source => $ids) {
                $hidden += TrailRoute::where('source', $source)
                    ->whereIn('type', array_keys($types))
                    ->whereNotIn('source_id', $ids)
                    ->where('is_published', true)
                    ->update(['is_published' => false]);
            }
            $this->info("{$hidden} verdwenen routes op onzichtbaar gezet");
        }

        $this->table(['Nieuw', 'Bijgewerkt', 'Ongewijzigd', 'Mislukt'], [array_values($counts)]);

        if ($dryRun) {
            $this->comment('Dry-run: er is niets opgeslagen.');
        }

        return $counts['failed'] > 0 && $counts['created'] + $counts['updated'] + $counts['unchanged'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
