<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reverb/Pusher-broadcaster die nooit een request of queue-job laat falen.
 *
 * Realtime is best-effort: de data is op het moment van broadcasten al
 * opgeslagen en de frontend haalt bij (her)verbinden en via polling alles
 * alsnog op. Een onbereikbare Reverb-server (herstart, crash — cron start 'm
 * binnen een minuut opnieuw) mag dus geen 500 op bv. het opslaan van een
 * waypoint geven. Fouten worden gelogd, maximaal één keer per minuut.
 */
class ResilientPusherBroadcaster extends PusherBroadcaster
{
    public function broadcast(array $channels, $event, array $payload = [])
    {
        try {
            parent::broadcast($channels, $event, $payload);
        } catch (\Throwable $e) {
            try {
                if (Cache::add('broadcast:failure-logged', true, 60)) {
                    Log::warning('[broadcast] mislukt (verdere fouten 60s onderdrukt): '.$e->getMessage(), [
                        'event' => $event,
                    ]);
                }
            } catch (\Throwable) {
                // Ook de cache/log mag hier niets breken.
            }
        }
    }
}
