<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time push naar alle missie-deelnemers wanneer de eigenaar/editor het
 * waarschuwingsbevel dispatcht ("New warning order"). De persistente melding
 * loopt via AppNotification; dit event geeft de live-toast zodat deelnemers het
 * meteen zien zonder refresh.
 *
 * ShouldBroadcastNow zodat het ook live komt zonder draaiende queue-worker.
 */
class WarningOrderDispatched implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $missionId,
        public string $missionName,
        public int $dispatchedByUserId,
        public string $dispatchedByName,
        public string $dispatchedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('mission.' . $this->missionId)];
    }

    public function broadcastWith(): array
    {
        return [
            'mission_id'    => $this->missionId,
            'mission_name'  => $this->missionName,
            'dispatched_by' => [
                'id'   => $this->dispatchedByUserId,
                'name' => $this->dispatchedByName,
            ],
            'dispatched_at' => $this->dispatchedAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'mission.warning_order';
    }
}
