<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Seintje na een batch-opslag van waypoints: andere deelnemers halen de lijst
 * opnieuw op. Bewust zonder de waypoints zelf — een batch van honderden punten
 * past niet in één broadcast-bericht (limiet ±10 KB bij Reverb/Pusher).
 */
class MapWaypointsReload implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $mapId,
        public int $count,
        public int $actorId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('map.' . $this->mapId)];
    }

    public function broadcastAs(): string
    {
        return 'waypoints.reload';
    }

    public function broadcastWith(): array
    {
        return [
            'count'    => $this->count,
            'actor_id' => $this->actorId,
        ];
    }
}
