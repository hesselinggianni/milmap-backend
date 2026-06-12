<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $table = 'leads';

    protected $fillable = [
        'email', 'source', 'utm_source', 'ip_address', 'user_agent', 'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
