<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarcandiOrder extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'email', 'edit_token', 'note', 'items', 'total_qty', 'total_price',
    ];

    protected $casts = [
        'items'       => 'array',
        'total_qty'   => 'integer',
        'total_price' => 'decimal:2',
    ];

    protected $hidden = ['edit_token'];

    public function shop()
    {
        return $this->belongsTo(MarcandiShop::class, 'shop_id');
    }
}
