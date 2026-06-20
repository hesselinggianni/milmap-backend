<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionRisk extends Model
{
    protected $table = 'mission_risks';

    protected $fillable = [
        'mission_id',
        'description',
        'likelihood',
        'impact',
        'mitigation',
        'sort_order',
    ];

    protected $casts = [
        'likelihood' => 'integer',
        'impact'     => 'integer',
    ];

    public function getRiskScoreAttribute(): int
    {
        return $this->likelihood * $this->impact;
    }

    public function getLevelAttribute(): string
    {
        $score = $this->risk_score;
        if ($score >= 16) return 'critical';
        if ($score >= 9)  return 'high';
        if ($score >= 4)  return 'medium';
        return 'low';
    }

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }
}
