<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time push naar alle missie-deelnemers wanneer de eigenaar de
 * missie-status wijzigt (planning → actief → afgerond). Geen DB-record,
 * geen permanente notificatie — clients tonen een lokale toast en updaten
 * de status-chip zodra het event binnenkomt.
 *
 * ShouldBroadcastNow zodat de melding ook live komt als er geen queue-worker
 * draait op de broadcaster-host.
 */
class MissionStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $missionId,
        public string $missionName,
        public string $oldStatus,
        public string $newStatus,
        public int $changedByUserId,
        public string $changedByName,
        public string $changedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('mission.' . $this->missionId)];
    }

    public function broadcastWith(): array
    {
        return [
            'mission_id'   => $this->missionId,
            'mission_name' => $this->missionName,
            'old_status'   => $this->oldStatus,
            'new_status'   => $this->newStatus,
            'changed_by'   => [
                'id'   => $this->changedByUserId,
                'name' => $this->changedByName,
            ],
            'changed_at'   => $this->changedAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'mission.status_changed';
    }
}
