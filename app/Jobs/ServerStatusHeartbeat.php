<?php

namespace App\Jobs;

use App\Services\ServerStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hartslag van de queue-worker: elke 5 minuten ingepland (bootstrap/app.php),
 * zodat admin → Server ziet dat `queue:work` uit de cron echt jobs verwerkt.
 */
class ServerStatusHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        ServerStatus::beat('queue');
    }
}
