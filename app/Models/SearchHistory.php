<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'map_id',
        'title',
        'subtitle',
        'lat',
        'lon',
        'created_at',
    ];
}