<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PublicRouteComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['subject_type', 'subject_id', 'user_id', 'body', 'mentions'];

    protected $casts = ['mentions' => 'array'];
}
