<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailCategory extends Model
{
    protected $table = 'mail_categories';

    protected $fillable = [
        'key', 'name', 'description', 'is_transactional',
    ];

    protected $casts = [
        'is_transactional' => 'boolean',
    ];
}
