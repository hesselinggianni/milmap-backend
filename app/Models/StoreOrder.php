<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOrder extends Model
{
    protected $fillable = [
        'user_id',
        'items', 'total_qty', 'total_price',
        'contact_email', 'contact_phone',
        'recipient_name', 'address_line', 'postal_code', 'city', 'country',
        'status',
    ];

    protected $casts = [
        'items' => 'array',
        'total_price' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
