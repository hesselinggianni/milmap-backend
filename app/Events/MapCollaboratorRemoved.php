<?php

namespace App\Events;

use App\Models\Map;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MapCollaboratorRemoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Map $map,
        public int $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('map.' . $this->map->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'collaborator.removed';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
        ];
    }
}
