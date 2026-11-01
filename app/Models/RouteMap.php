<?php

namespace App\Models;

use App\Support\RouteGeometry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteMap extends Model
{
    use HasUuids;
    use \App\Models\Concerns\BelongsToConnectOrganization;
    use SoftDeletes;

    protected $table = 'route_maps';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'map_id',
        'mission_id',
        'owner_id',

        // Kaart-en-kompasopdracht (RouteAssignmentController). Bewust niet in
        // de update()-whitelist: alleen de opdracht zet/wijzigt deze.
        'route_assignment_id',
        'route_code',

        'title',
        'date',
        'time',
        'color',
        'declination',
        'equipment',
        'speed',
        'ic',
        'cs',
        'locations',

        'pause_time',
        'total_time',
        'total_distance',
        'total_elevation',

        'meta',
        'column_settings',
        'column_order',

        'bijlage',
        'callsign',
        'eenheid',
        'sterkte',
        'kaartblad',
        'vtv_vta',

        // Garmin-push-status (server-side gezet door PushRouteMapToGarmin,
        // niet via de gewone update()-endpoint — die valideert een expliciete
        // whitelist die deze velden niet bevat).
        'garmin_course_id',
        'garmin_push_status',
        'garmin_pushed_at',
        'garmin_push_error',

        // Openbaar delen (profielpagina) + herkomst bij klonen. Bewust niet in
        // de update()-whitelist: alleen PublicRouteController zet deze.
        'is_public',
        'published_at',
        'cloned_from_type',
        'cloned_from_id',
        'cloned_from_user_id',

        // Route-ontdekking: gesnapt pad, sport, hoogteprofiel en omhullende.
        // Bewust niet rechtstreeks door de client te zetten (de controller
        // zet ze na validatie); `source`/`attribution` alleen door de server.
        'sport',
        'difficulty',
        'path_polyline',
        'path_hash',
        'elevation_profile',
        'ascent_m',
        'descent_m',
        'bbox_min_lat',
        'bbox_min_lon',
        'bbox_max_lat',
        'bbox_max_lon',
        'source',
        'source_ref',
        'attribution',
    ];

    protected $hidden = ['path_hash'];

    protected $casts = [
        'locations' => 'array',
        'date' => 'date:Y-m-d',

        'meta' => 'array',
        'column_settings' => 'array',
        'column_order' => 'array',
        'bijlage' => 'array',
        'sterkte' => 'integer',

        'declination' => 'float',

        'pause_time' => 'integer',
        'total_time' => 'integer',

        'total_distance' => 'float',
        'total_elevation' => 'float',

        'garmin_pushed_at' => 'datetime',

        'is_public' => 'boolean',
        'published_at' => 'datetime',

        'elevation_profile' => 'array',
        'ascent_m' => 'integer',
        'descent_m' => 'integer',
        'bbox_min_lat' => 'float',
        'bbox_min_lon' => 'float',
        'bbox_max_lat' => 'float',
        'bbox_max_lon' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (RouteMap $route) {
            $route->syncDiscoveryFields();
        });
    }

    /**
     * Houdt de afgeleide velden voor route-ontdekking kloppend, ongeacht langs
     * welke weg de route wordt opgeslagen (planner, import, klonen, …):
     *  - een nieuw pad krijgt de vingerafdruk van de waypoints waar het bij hoort;
     *  - veranderen de waypoints zonder nieuw pad, dan klopt het opgeslagen pad
     *    niet meer en wordt het (met profiel en stijgen/dalen) gewist;
     *  - de omhullende (bbox) volgt het pad, of anders de waypoints.
     */
    public function syncDiscoveryFields(): void
    {
        $locations = is_array($this->locations) ? $this->locations : [];
        $hasPath = filled($this->path_polyline);

        if ($hasPath && $this->isDirty('path_polyline')) {
            $this->path_hash = RouteGeometry::locationsHash($locations);
        } elseif ($hasPath && $this->isDirty('locations') && $this->path_hash !== RouteGeometry::locationsHash($locations)) {
            foreach (['path_polyline', 'path_hash', 'elevation_profile', 'ascent_m', 'descent_m'] as $field) {
                $this->{$field} = null;
            }
            $hasPath = false;
        }

        // Een hoogteprofiel en stijgen/dalen zonder pad zeggen niets: ze horen bij het pad.
        // (Zo kan een oud profiel dat een offline wachtrij nog meestuurt geen weeskind worden.)
        if (! $hasPath) {
            foreach (['elevation_profile', 'ascent_m', 'descent_m'] as $field) {
                if ($this->{$field} !== null) {
                    $this->{$field} = null;
                }
            }
        }

        if ($this->isDirty(['path_polyline', 'locations']) || $this->bbox_min_lat === null) {
            $bounds = $hasPath ? RouteGeometry::bounds(RouteGeometry::decode((string) $this->path_polyline)) : null;
            $bounds ??= RouteGeometry::boundsOfLocations($locations);

            if ($bounds) {
                [$this->bbox_min_lat, $this->bbox_min_lon, $this->bbox_max_lat, $this->bbox_max_lon] = $bounds;
            } elseif ($this->bbox_min_lat !== null) {
                $this->bbox_min_lat = $this->bbox_min_lon = $this->bbox_max_lat = $this->bbox_max_lon = null;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function map()
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function mission()
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function assignment()
    {
        return $this->belongsTo(RouteAssignment::class, 'route_assignment_id');
    }

    public function generatedRoutes()
    {
        return $this->hasMany(GeneratedRoute::class, 'route_map_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RouteMapPhoto::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function getCheckpointCountAttribute(): int
    {
        return collect($this->locations)
            ->filter(fn ($loc) =>
                data_get($loc, 'checkpoint.enabled') === true
            )
            ->count();
    }
}
