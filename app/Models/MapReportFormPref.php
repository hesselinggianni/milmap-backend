<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Door één gebruiker gekozen meldingsformulieren voor één kaart.
 * Zie migratie create_map_report_form_prefs_table.
 */
class MapReportFormPref extends Model
{
    use HasUuids;

    protected $table = 'map_report_form_prefs';

    protected $fillable = [
        'user_id',
        'map_id',
        'hidden',
    ];

    protected $casts = [
        'hidden' => 'array',
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
