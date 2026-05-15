<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Map extends Model
{
    use HasUuids;

    protected $table = 'maps';

    protected $fillable = [
        'title',
        'settings',
        'status',
        'owner_id',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    // default UUID generation
    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }
}