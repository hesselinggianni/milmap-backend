<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioSnapshot extends Model
{
    protected $fillable = [
        'account_id', 'equity_eur', 'cash_eur', 'positions_value_eur',
        'unrealized_pnl', 'realized_pnl_cum', 'captured_at',
    ];

    protected $casts = [
        'equity_eur' => 'string',
        'cash_eur' => 'string',
        'positions_value_eur' => 'string',
        'unrealized_pnl' => 'string',
        'realized_pnl_cum' => 'string',
        'captured_at' => 'datetime',
    ];
}
