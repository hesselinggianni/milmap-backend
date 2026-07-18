<?php

namespace App\Console\Commands;

use App\Services\PageSpeedService;
use App\Services\PageSpeedTaskService;
use Illuminate\Console\Command;

class MonitorPageSpeed extends Command
{
    protected $signature = 'pagespeed:monitor {--no-tasks : Alleen meten, geen taken genereren}';

    protected $description = 'Meet de PageSpeed-scores van de gemonitorde URL\'s en maak taken uit lage scores.';

    public function handle(PageSpeedService $ps, PageSpeedTaskService $tasks): int
    {
        $run = $ps->run();
        $this->info("PageSpeed: {$run['measured']} gemeten, {$run['failed']} mislukt.");

        if (! $this->option('no-tasks')) {
            $t = $tasks->generate();
            $this->info("Taken: {$t['created']} aangemaakt, {$t['updated']} bijgewerkt ({$t['signals']} signalen).");
        }

        return self::SUCCESS;
    }
}
