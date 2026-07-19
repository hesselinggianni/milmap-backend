<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletBalance extends Model
{
    protected $fillable = ['account_id', 'asset', 'available', 'reserved'];

    // Kept as decimal strings — cast to BigDecimal at use-site, never float.
    protected $casts = [
        'available' => 'string',
        'reserved' => 'string',
    ];
}
