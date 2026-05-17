<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'map_id',
        'user_id',
        'category',
        'type',
        'subtype',
        'latitude',
        'longitude',
        'urgency',
        'timing',
        'size',
        'count',
        'activity',
        'equipment',
        'risk',
        'hazardType',
        'avalancheLevel',
        'roadCondition',
        'weatherCondition',
        'description',
        'metadata',
        'status',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'activity' => 'array',
        'equipment' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Relationship to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship to Map (optional)
     */
    public function map()
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * Scope: Filter by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Filter by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Recent reports
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
