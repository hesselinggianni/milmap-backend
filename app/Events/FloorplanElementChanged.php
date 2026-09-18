<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FloorplanElementChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $floorplanId,
        public array  $element,  // toClientArray()
        public string $action,   // 'created' | 'updated' | 'deleted'
        public int    $actorId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('floorplan.' . $this->floorplanId)];
    }

    public function broadcastAs(): string
    {
        return 'element.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action'   => $this->action,
            'element'  => $this->element,
            'actor_id' => $this->actorId,
        ];
    }
}
