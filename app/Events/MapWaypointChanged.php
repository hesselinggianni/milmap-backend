<?php

namespace App\Events;

use App\Models\MapWaypoint;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MapWaypointChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $mapId,
        public array  $waypoint,  // toClientArray() + deleted flag
        public string $action,    // 'created' | 'updated' | 'deleted'
        public int    $actorId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('map.' . $this->mapId)];
    }

    public function broadcastAs(): string
    {
        return 'waypoint.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action'   => $this->action,
            'waypoint' => $this->waypoint,
            'actor_id' => $this->actorId,
        ];
    }
}
