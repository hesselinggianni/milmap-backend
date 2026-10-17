<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Kaart-en-kompasopdracht: bundelt gegenereerde routekaarten, deelnemers en
 * de planning. Toegang loopt altijd via de kaart (MapPolicy) — een opdracht
 * heeft geen eigen deel- of organisatieregels.
 */
class RouteAssignment extends Model
{
    use HasUuids;

    protected $table = 'route_assignments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'map_id',
        'owner_id',
        'title',
        'type',
        'settings',
        'participants',
    ];

    protected $casts = [
        'settings' => 'array',
        'participants' => 'array',
    ];

    public function map()
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function collaborators()
    {
        return $this->hasMany(RouteAssignmentCollaborator::class, 'route_assignment_id');
    }

    public function logs()
    {
        return $this->hasMany(RouteAssignmentLog::class, 'route_assignment_id');
    }

    public function routeMaps()
    {
        return $this->hasMany(RouteMap::class, 'route_assignment_id');
    }
}
