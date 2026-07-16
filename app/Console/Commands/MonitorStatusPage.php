<?php

namespace App\Console\Commands;

use App\Services\StatusPageMonitor;
use Illuminate\Console\Command;

/**
 * status:monitor — pingt elke enabled status-page-domein, bewaart de check
 * (voor de uptime-balken) en opent/sluit automatisch incidenten. Draait via de
 * scheduler elke minuut; voedt de publieke endpoints /status/uptime & /incidents
 * die milmap.nl/status toont.
 */
class MonitorStatusPage extends Command
{
    protected $signature = 'status:monitor';

    protected $description = 'Monitor the status-page domains and record uptime + incidents';

    public function handle(StatusPageMonitor $monitor): int
    {
        $results = $monitor->monitorAll();
        foreach ($results as $r) {
            $this->info("Checked {$r['domainId']} → {$r['status']}");
        }
        $this->info('Status-page monitor: '.count($results).' domeinen gecontroleerd.');

        return self::SUCCESS;
    }
}
