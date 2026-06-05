<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'type',
        'name',
        'mission_id',
        'created_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────

    public function participants()
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mission()
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    // ── Helpers ────────────────────────────────────────────────────

    public function hasParticipant(int $userId): bool
    {
        return $this->participants()->where('users.id', $userId)->exists();
    }

    /**
     * Is this a group ('channel') conversation? Mission group chats use the
     * 'channel' type; 1-on-1s use 'direct'.
     */
    public function isGroup(): bool
    {
        return $this->type !== 'direct';
    }
}
