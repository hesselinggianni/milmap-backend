<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyConfig extends Model
{
    protected $fillable = [
        'account_id', 'strategy_key', 'market', 'params', 'is_active',
    ];

    protected $casts = [
        'params' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
