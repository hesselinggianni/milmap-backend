<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'account_id', 'market', 'quantity', 'avg_entry_price',
        'stop_loss_price', 'take_profit_price', 'opened_at', 'status', 'realized_pnl',
    ];

    protected $casts = [
        'quantity' => 'string',
        'avg_entry_price' => 'string',
        'stop_loss_price' => 'string',
        'take_profit_price' => 'string',
        'realized_pnl' => 'string',
        'opened_at' => 'datetime',
    ];
}
