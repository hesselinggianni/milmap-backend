<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionAuditLog extends Model
{
    protected $table = 'mission_audit_log';
    public $timestamps = false;

    protected $fillable = [
        'mission_id',
        'user_id',
        'action',
        'payload',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
