<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailPushOptin extends Model
{
    protected $table = 'mail_push_optins';

    protected $fillable = [
        'user_id', 'category_id',
    ];
}
