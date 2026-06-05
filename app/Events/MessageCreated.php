<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
    }

    /**
     * Broadcast on the conversation's private channel. Every participant
     * listens here; each client decrypts the ciphertext sealed to their key.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * The wire payload carries BOTH sealed copies plus sender id; each client
     * picks the ciphertext it can open. The server still sees only ciphertext.
     */
    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id'       => $this->message->sender_id,
            'encryption'      => $this->message->encryption,
            'ciphertext'      => $this->message->ciphertext,
            'ciphertext_self' => $this->message->ciphertext_self,
            'created_at'      => $this->message->created_at?->toIso8601String(),
        ];
    }
}
