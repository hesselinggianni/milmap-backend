<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Levenscyclus- en toegangslog van een KAKO-app-deelname (toestemming, venster, wie keek mee). */
class KakoAppEvent extends Model
{
    public $timestamps = false;

    protected $table = 'kako_app_events';

    protected $fillable = [
        'route_assignment_id', 'participant_id', 'actor_user_id', 'actor_name', 'type', 'data', 'created_at',
    ];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
    ];
}
