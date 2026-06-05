<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'ciphertext',
        'ciphertext_self',
        'encryption',
    ];

    // ── Relations ──────────────────────────────────────────────────

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // ── Serialisation ──────────────────────────────────────────────

    /**
     * Return the payload for a given viewer. The viewer receives the
     * ciphertext sealed to THEIR public key: senders get ciphertext_self,
     * recipients get ciphertext. The server never sees plaintext.
     */
    public function forViewer(int $viewerId): array
    {
        $isSender = $this->sender_id === $viewerId;

        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id'       => $this->sender_id,
            'mine'            => $isSender,
            'encryption'      => $this->encryption,
            'ciphertext'      => $isSender ? ($this->ciphertext_self ?? $this->ciphertext) : $this->ciphertext,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
