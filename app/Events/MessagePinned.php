<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast wanneer een deelnemer een bericht vastzet of losmaakt in een
 * gesprek. Pure metadata (geen inhoud): elk deelnemend apparaat werkt zijn
 * pin-banner en het 📌-vinkje op de bubbel live bij.
 */
class MessagePinned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int|string $conversationId,
        public int $messageId,
        public bool $pinned,
        public ?int $pinnedBy = null,
        public ?string $pinnedAt = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.pinned';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id'      => $this->messageId,
            'pinned'          => $this->pinned,
            'pinned_by'       => $this->pinnedBy,
            'pinned_at'       => $this->pinnedAt,
        ];
    }
}
