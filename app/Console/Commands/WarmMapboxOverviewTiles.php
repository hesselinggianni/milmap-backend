<?php

namespace App\Console\Commands;

use App\Http\Controllers\MapboxOverviewTileController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class WarmMapboxOverviewTiles extends Command
{
    protected $signature = 'tiles:warm-mapbox-overview
        {--style=* : Leeg = alle vier stijlen}
        {--language=nl}
        {--concurrency=8}
        {--max-zoom=6 : Hoogste zoom (0 t/m 6; hoger blijft bij Mapbox)}
        {--estimate : Download een steekproef en schat de totale grootte}
        {--dry-run}';

    protected $description = 'Download wereldwijde Mapbox-tegels op zoom 0 t/m 6 (inclusief wereldbol) als lokale WebP (Python + Pillow vereist); hogere zooms blijven bij Mapbox';

    public function handle(): int
    {
        if ((int) $this->option('max-zoom') > MapboxOverviewTileController::MAX_ZOOM) {
            $this->error('Hoogste toegestane zoom op de eigen server is ' . MapboxOverviewTileController::MAX_ZOOM . '; hogere zooms lopen via Mapbox.');
            return self::INVALID;
        }
        $styles = $this->option('style');
        foreach ($styles as $style) {
            if (! in_array($style, MapboxOverviewTileController::STYLES, true)) {
                $this->error('Onbekende kaartstijl: ' . $style);
                return self::INVALID;
            }
        }
        if (! $this->option('dry-run') && ! config('services.mapbox.token')) {
            $this->error('MAPBOX_TOKEN ontbreekt.');
            return self::FAILURE;
        }
        $command = [config('services.mapbox.python', 'python3'), base_path('scripts/download-mapbox-overview.py'),
            '--output', storage_path('app/tiles/mapbox-overview'),
            '--max-zoom', $this->option('max-zoom'),
            '--language', $this->option('language'), '--concurrency', $this->option('concurrency')];
        if (PHP_OS_FAMILY === 'Darwin') {
            $command = array_merge(['/usr/bin/arch', '-arm64'], $command);
        }
        foreach ($styles as $style) {
            array_push($command, '--style', $style);
        }
        if ($this->option('estimate')) {
            $command[] = '--estimate';
        }
        if ($this->option('dry-run')) {
            $command[] = '--dry-run';
        }
        return Process::forever()->env(['MAPBOX_TOKEN' => config('services.mapbox.token', '')])
            ->run($command, fn ($type, $output) => $this->output->write($output))->exitCode();
    }
}
