<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmActivity extends Model
{
    protected $table = 'crm_activities';

    protected $fillable = [
        'deal_id', 'type', 'body', 'goal', 'meta', 'happened_at', 'created_by',
    ];

    protected $casts = [
        'meta'        => 'array',
        'happened_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'deal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toApiArray(): array
    {
        return [
            'id'          => $this->id,
            'deal_id'     => $this->deal_id,
            'type'        => $this->type,
            'body'        => $this->body,
            'goal'        => $this->goal,
            'meta'        => $this->meta,
            'happened_at' => optional($this->happened_at)->toIso8601String(),
            'created_by'  => $this->creator?->first_name
                ? trim($this->creator->first_name . ' ' . ($this->creator->last_name ?? ''))
                : null,
            'created_at'  => optional($this->created_at)->toIso8601String(),
        ];
    }
}
