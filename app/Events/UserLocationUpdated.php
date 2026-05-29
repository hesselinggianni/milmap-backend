<?php

namespace App\Events;

use App\Models\UserLocation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public UserLocation $location)
    {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('map.' . $this->location->map_id . '.locations'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->location->user_id,
            'user_name' => $this->location->user->name,
            'map_id' => $this->location->map_id,
            'latitude' => $this->location->latitude,
            'longitude' => $this->location->longitude,
            'accuracy' => $this->location->accuracy,
            'heading' => $this->location->heading,
            'speed' => $this->location->speed,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'location.updated';
    }
}
