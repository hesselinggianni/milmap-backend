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
        'reply_to_id',
        'ciphertext',
        'ciphertext_self',
        'ciphertexts',
        'encryption',
        'type',
        'pinned_at',
        'pinned_by',
    ];

    protected $casts = [
        // Per-recipient sealed boxes for group messages: { "<userId>": "<box>" }
        'ciphertexts' => 'array',
        'pinned_at'   => 'datetime',
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

    /** Het bericht waarop dit bericht een antwoord is (quote). */
    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
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
        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id'       => $this->sender_id,
            'mine'            => $this->sender_id === $viewerId,
            'type'            => $this->type ?? 'text',
            'encryption'      => $this->encryption,
            'ciphertext'      => $this->ciphertextForViewer($viewerId),
            'reactions'       => $this->reactionSummary(),
            // Antwoord-op: compacte kopie van het originele bericht (verzegeld
            // voor deze viewer) zodat de client de quote lokaal kan ontsleutelen,
            // ook als het origineel buiten het geladen venster valt. NULL als
            // het origineel inmiddels is ingetrokken.
            'reply_to_id'     => $this->reply_to_id,
            'reply_to'        => $this->reply_to_id ? $this->replyTo?->replyPreviewForViewer($viewerId) : null,
            'pinned_at'       => $this->pinned_at?->toIso8601String(),
            'pinned_by'       => $this->pinned_by,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }

    /** Pick the sealed box this viewer can open (shared by forViewer/reply preview). */
    private function ciphertextForViewer(int $viewerId): ?string
    {
        if (is_array($this->ciphertexts) && count($this->ciphertexts) > 0) {
            // Group message: pick the box sealed to this viewer.
            return $this->ciphertexts[(string) $viewerId] ?? null;
        }

        // 1-on-1 (or 'none'): sender reads its own copy, recipient the other.
        return $this->sender_id === $viewerId
            ? ($this->ciphertext_self ?? $this->ciphertext)
            : $this->ciphertext;
    }

    /**
     * Compacte weergave van dit bericht als quote in een antwoord: net genoeg
     * om de voorbeeldregel te renderen (afzender + lokaal ontsleutelde tekst),
     * zonder reacties/pin-metadata en zonder verdere reply-recursie.
     */
    public function replyPreviewForViewer(int $viewerId): array
    {
        return [
            'id'         => $this->id,
            'sender_id'  => $this->sender_id,
            'mine'       => $this->sender_id === $viewerId,
            'type'       => $this->type ?? 'text',
            'encryption' => $this->encryption,
            'ciphertext' => $this->ciphertextForViewer($viewerId),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
