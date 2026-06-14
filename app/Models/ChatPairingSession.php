<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kortlevende, eenmalige sessie voor QR-apparaatkoppeling van de E2EE chat.
 * Zie de migratie voor het zero-knowledge model. De rij wordt verwijderd zodra
 * het tonende apparaat de sealed payload heeft opgehaald (of bij verlopen).
 */
class ChatPairingSession extends Model
{
    protected $table = 'chat_pairing_sessions';

    /** Willekeurige string-token als PK, geen auto-increment. */
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'ephemeral_public_key',
        'sealed_payload',
        'claimed_at',
        'expires_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Verlopen sessies tellen niet meer mee. */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
