<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'email',
        'invited_user_id',
        'invitable_type',
        'invitable_id',
        'role',
        'invited_by',
        'token',
        'status',
        'accepted_by',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    // ── Relations ───────────────────────────────────────────────────

    public function invitable(): MorphTo
    {
        return $this->morphTo();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForEmail($query, string $email)
    {
        return $query->where('email', mb_strtolower(trim($email)));
    }

    /** A bound invitation follows the original account, even after an email change. */
    public function scopeAddressedTo($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query->where('invited_user_id', $user->id)
                ->orWhere(function ($query) use ($user) {
                    $query->whereNull('invited_user_id')
                        ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($user->email))]);
                });
        });
    }

    public function isAddressedTo(User $user): bool
    {
        if ($this->invited_user_id !== null) {
            return (int) $this->invited_user_id === (int) $user->id;
        }

        $email = mb_strtolower(trim((string) $user->email));

        return $email !== '' && $email === mb_strtolower(trim((string) $this->email));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Human-readable title of the invited resource ("name" for missions,
     * "title" for maps). Safe even if the related resource was deleted.
     */
    public function resourceTitle(): string
    {
        $r = $this->invitable;
        if (! $r) return 'een werkruimte';
        return (string) ($r->name ?? $r->title ?? 'een werkruimte');
    }

    /**
     * Short type key for the UI: 'mission' | 'map' | lowercased class basename.
     */
    public function resourceType(): string
    {
        return Str::of(class_basename($this->invitable_type))->lower();
    }

    /**
     * Dutch label for the resource type.
     */
    public function resourceLabel(): string
    {
        return match ($this->resourceType()) {
            'mission'      => 'missie',
            'map'          => 'kaart',
            'team'         => 'team',
            'conversation' => 'chat',
            default        => 'werkruimte',
        };
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::where('token', $token)->exists());

        return $token;
    }
}
