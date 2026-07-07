<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarcandiProduct extends Model
{
    protected $fillable = [
        'shop_id', 'sort', 'kind', 'level', 'regel', 'code', 'name', 'price', 'active',
    ];

    protected $casts = [
        'sort'   => 'integer',
        'level'  => 'integer',
        'price'  => 'decimal:2',
        'active' => 'boolean',
    ];
}
