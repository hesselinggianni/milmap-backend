<?php

namespace App\Console\Commands;

use App\Services\SeoTaskService;
use Illuminate\Console\Command;

/**
 * Genereert dagelijks SEO-taken uit Google Search Console-signalen (lage CTR,
 * net-buiten-de-top zoekwoorden). Draait niets als GSC niet is geconfigureerd.
 */
class GenerateSeoTasks extends Command
{
    protected $signature = 'seo:generate-tasks';
    protected $description = 'Maak SEO-taken uit Google Search Console-signalen';

    public function handle(SeoTaskService $seo): int
    {
        $r = $seo->generate();
        if (! empty($r['error'])) {
            $this->warn($r['error']);
            return self::SUCCESS;
        }
        $this->info("SEO-taken: {$r['created']} nieuw, {$r['updated']} bijgewerkt (van {$r['signals']} signalen).");
        return self::SUCCESS;
    }
}
