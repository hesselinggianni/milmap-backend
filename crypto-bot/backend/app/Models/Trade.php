<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $fillable = [
        'order_id', 'account_id', 'market', 'side',
        'price', 'quantity', 'fee', 'fee_asset', 'executed_at',
    ];

    protected $casts = [
        'price' => 'string',
        'quantity' => 'string',
        'fee' => 'string',
        'executed_at' => 'datetime',
    ];
}
