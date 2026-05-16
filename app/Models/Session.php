<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'language',
        'timezone',
        'ip_address',
        'user_agent',
        'payload',
        'last_activity',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_activity' => 'datetime',
    ];
}