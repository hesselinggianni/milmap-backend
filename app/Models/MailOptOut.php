<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailOptOut extends Model
{
    protected $table = 'mail_opt_outs';

    protected $fillable = [
        'email', 'category_id', 'unsubscribed_at', 'source',
    ];

    protected $casts = [
        'unsubscribed_at' => 'datetime',
    ];
}
