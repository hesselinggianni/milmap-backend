<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a single participant's latest GPS position to the mission's
 * presence channel so teammates see live movement on the mission map.
 *
 * Dispatched from MissionTrackController::store() each time a participant
 * uploads track points (the client only uploads when the user has consented
 * to share their location, so appearing here is always opt-in).
 *
 * Uses ShouldBroadcastNow (synchronous) so positions appear in real time even
 * when no queue worker is running on the broadcasting host.
 */
class MissionLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $missionId,
        public int $userId,
        public string $userName,
        public float $lat,
        public float $lng,
        public ?float $heading = null,
        public ?float $speed = null,
        public ?float $accuracy = null,
        public ?string $recordedAt = null,
    ) {
        //
    }

    /**
     * The mission presence channel (authorised in routes/channels.php for any
     * participant via Mission::hasAccess()).
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('mission.' . $this->missionId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'user_id'     => $this->userId,
            'name'        => $this->userName,
            'lat'         => $this->lat,
            'lng'         => $this->lng,
            'heading'     => $this->heading,
            'speed'       => $this->speed,
            'accuracy'    => $this->accuracy,
            'recorded_at' => $this->recordedAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }
}
