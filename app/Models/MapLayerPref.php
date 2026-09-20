<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Voorkeuren van één gebruiker voor één kaart-als-layer in de /maps-workspace.
 * Zie migratie create_map_layer_prefs_table.
 */
class MapLayerPref extends Model
{
    use HasUuids;

    protected $table = 'map_layer_prefs';

    protected $fillable = [
        'user_id',
        'map_id',
        'visible',
        'color',
        'order',
    ];

    protected $casts = [
        'visible' => 'boolean',
        'order'   => 'integer',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function map()
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
