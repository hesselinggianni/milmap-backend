<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactTicket extends Model
{
    protected $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'type',
        'status',
        'priority',
    ];
}
