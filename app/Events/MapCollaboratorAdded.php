<?php

namespace App\Events;

use App\Models\MapCollaborator;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MapCollaboratorAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MapCollaborator $collaborator,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('map.' . $this->collaborator->map_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'collaborator.added';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->collaborator->id,
            'user_id' => $this->collaborator->user_id,
            'user_name' => $this->collaborator->user?->full_name ?? $this->collaborator->user?->email,
            'email' => $this->collaborator->user?->email,
            'status' => $this->collaborator->status,
            'invited_at' => $this->collaborator->invited_at,
            'accepted_at' => $this->collaborator->accepted_at,
        ];
    }
}
