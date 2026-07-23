<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Candle extends Model
{
    protected $fillable = [
        'market', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume',
    ];

    protected $casts = [
        'open_time' => 'integer',
        'open' => 'string',
        'high' => 'string',
        'low' => 'string',
        'close' => 'string',
        'volume' => 'string',
    ];
}
