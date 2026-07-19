<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventLog extends Model
{
    public const UPDATED_AT = null; // append-only audit log

    protected $fillable = ['account_id', 'type', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];

    /** Convenience factory for the audit trail. */
    public static function record(string $type, string $message, array $context = [], string $level = 'info', ?int $accountId = null): self
    {
        return static::create([
            'account_id' => $accountId,
            'type' => $type,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
