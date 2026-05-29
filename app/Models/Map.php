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

    /**
     * Get the route maps for this map.
     */
    public function routeMaps()
    {
        return $this->hasMany(RouteMap::class, 'map_id');
    }

    /**
     * Get the owner of this map.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all collaborators for this map.
     */
    public function collaborators()
    {
        return $this->hasMany(MapCollaborator::class, 'map_id');
    }
}