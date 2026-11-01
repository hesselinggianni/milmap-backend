<?php

namespace App\Models;

use App\Support\TrailRoutePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TrailRoute extends Model
{
    protected $fillable = [
        'source', 'source_id', 'slug', 'name', 'type', 'country', 'province',
        'distance_km', 'is_loop', 'segments', 'start_lat', 'start_lon',
        'min_lat', 'min_lon', 'max_lat', 'max_lon', 'geometry', 'preview', 'point_count', 'gpx_path',
        'network', 'operator', 'website', 'source_url', 'attribution', 'is_published',
    ];

    protected $hidden = ['source', 'source_id', 'gpx_path'];

    protected $casts = [
        'geometry' => 'array',
        'preview' => 'array',
        'point_count' => 'integer',
        'is_loop' => 'boolean',
        'is_published' => 'boolean',
        'distance_km' => 'float',
        'start_lat' => 'float',
        'start_lon' => 'float',
        // Zonder cast vergelijkt Eloquent MySQL-decimals als tekst ("6.5000000" ≠ "6.5"):
        // dan telt elke herhaalde import die rijen steeds als gewijzigd.
        'min_lat' => 'float',
        'min_lon' => 'float',
        'max_lat' => 'float',
        'max_lon' => 'float',
    ];

    /** Alle kolommen behalve de zware `geometry`: genoeg voor lijsten en kaartmarkers. */
    public const LIST_COLUMNS = [
        'id', 'source', 'source_id', 'slug', 'name', 'type', 'country', 'province',
        'distance_km', 'is_loop', 'segments', 'start_lat', 'start_lon',
        'min_lat', 'min_lon', 'max_lat', 'max_lon', 'preview', 'point_count', 'gpx_path',
        'network', 'operator', 'website', 'source_url', 'attribution', 'is_published',
        'created_at', 'updated_at',
    ];

    protected static function booted(): void
    {
        // De preview volgt de geometry: bij elke wijziging (import) opnieuw uitdunnen.
        static::saving(function (self $route) {
            if (! array_key_exists('geometry', $route->getAttributes())) {
                return; // gedeeltelijk geladen model: geometry is niet aangeraakt
            }
            if ($route->isDirty('geometry') || $route->getAttribute('preview') === null) {
                $computed = TrailRoutePresenter::previewFor($route->geometry);
                $route->preview = $computed['preview'];
                $route->point_count = $computed['point_count'];
            }
        });
    }

    public function scopeWithoutGeometry(Builder $query): Builder
    {
        return $query->select(self::LIST_COLUMNS);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeInBounds(Builder $query, float $south, float $west, float $north, float $east): Builder
    {
        return $query->where('max_lat', '>=', $south)
            ->where('min_lat', '<=', $north)
            ->where('max_lon', '>=', $west)
            ->where('min_lon', '<=', $east);
    }
}
