<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcHrRequest extends Model
{
    protected $fillable = ['name', 'email', 'items', 'total_days'];

    protected $casts = [
        'items'      => 'array',
        'total_days' => 'integer',
    ];
}
