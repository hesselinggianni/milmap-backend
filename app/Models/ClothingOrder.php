<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
 * Tijdelijke kledingbestelling. Geen relaties naar users — standalone.
 */
class ClothingOrder extends Model
{
    protected $fillable = [
        'name',
        'email',
        'edit_token',
        'note',
        'items',
        'total_qty',
        'total_price',
    ];

    protected $hidden = [
        'edit_token',
    ];

    protected $casts = [
        'items'       => 'array',
        'total_qty'   => 'integer',
        'total_price' => 'decimal:2',
    ];
}
