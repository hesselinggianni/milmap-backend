<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteAssignmentLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['route_assignment_id', 'user_id', 'user_name', 'action', 'summary', 'changes', 'created_at'];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];
}
