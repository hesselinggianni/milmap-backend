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
        'ciphertexts',
        'encryption',
        'type',
    ];

    protected $casts = [
        // Per-recipient sealed boxes for group messages: { "<userId>": "<box>" }
        'ciphertexts' => 'array',
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

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    // ── Serialisation ──────────────────────────────────────────────

    /**
     * Aggregate raw reaction rows into a per-emoji summary:
     *   [ { emoji, count, user_ids: [..] }, .. ]
     * `user_ids` lets each client derive whether the viewer reacted, without the
     * server needing to know who is asking. Emoji are plain metadata (not the
     * E2EE message body), so this carries no decrypted content.
     */
    public function reactionSummary(): array
    {
        if (! $this->relationLoaded('reactions')) {
            $this->load('reactions');
        }

        return $this->reactions
            ->groupBy('emoji')
            ->map(fn ($rows, $emoji) => [
                'emoji'    => (string) $emoji,
                'count'    => $rows->count(),
                'user_ids' => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Return the payload for a given viewer. The viewer receives the
     * ciphertext sealed to THEIR public key. The server never sees plaintext.
     *
     * - Group messages carry a `ciphertexts` map keyed by user id; the viewer
     *   gets the box sealed to them (null if they were not a recipient).
     * - 1-on-1 messages carry the recipient/self pair: senders get
     *   ciphertext_self, recipients get ciphertext.
     * - 'none'-encrypted messages (e.g. attachments) store the same payload in
     *   `ciphertext` for everyone.
     */
    public function forViewer(int $viewerId): array
    {
        $isSender = $this->sender_id === $viewerId;

        if (is_array($this->ciphertexts) && count($this->ciphertexts) > 0) {
            // Group message: pick the box sealed to this viewer.
            $ciphertext = $this->ciphertexts[(string) $viewerId] ?? null;
        } else {
            // 1-on-1 (or 'none'): sender reads its own copy, recipient the other.
            $ciphertext = $isSender ? ($this->ciphertext_self ?? $this->ciphertext) : $this->ciphertext;
        }

        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id'       => $this->sender_id,
            'mine'            => $isSender,
            'type'            => $this->type ?? 'text',
            'encryption'      => $this->encryption,
            'ciphertext'      => $ciphertext,
            'reactions'       => $this->reactionSummary(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
