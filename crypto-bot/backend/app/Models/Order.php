<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'account_id', 'market', 'side', 'type', 'requested_qty', 'limit_price',
        'status', 'exchange_order_id', 'client_order_id', 'reject_reason',
        'strategy_config_id', 'signal_meta',
    ];

    protected $casts = [
        'requested_qty' => 'string',
        'limit_price' => 'string',
        'signal_meta' => 'array',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
