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
            'type'            => $this->message->type ?? 'text',
            'encryption'      => $this->message->encryption,
            'ciphertext'      => $this->message->ciphertext,
            'ciphertext_self' => $this->message->ciphertext_self,
            // Group message: each client picks the box sealed to its own user id.
            'ciphertexts'     => $this->message->ciphertexts,
            // Antwoord-op: alle sealed kopieën van het origineel meegeven — het
            // kanaal is gedeeld, dus per-viewer trimmen kan hier niet. Elke
            // client kiest (net als bij het hoofdbericht) zijn eigen box.
            'reply_to_id'     => $this->message->reply_to_id,
            'reply_to'        => $this->message->reply_to_id ? $this->replyPayload() : null,
            'created_at'      => $this->message->created_at?->toIso8601String(),
        ];
    }

    private function replyPayload(): ?array
    {
        $parent = $this->message->replyTo;
        if (! $parent) {
            return null;
        }

        return [
            'id'              => $parent->id,
            'sender_id'       => $parent->sender_id,
            'type'            => $parent->type ?? 'text',
            'encryption'      => $parent->encryption,
            'ciphertext'      => $parent->ciphertext,
            'ciphertext_self' => $parent->ciphertext_self,
            'ciphertexts'     => $parent->ciphertexts,
            'created_at'      => $parent->created_at?->toIso8601String(),
        ];
    }
}
