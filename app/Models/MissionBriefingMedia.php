<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class MissionBriefingMedia extends Model {
    use HasUuids;
    protected $table = 'mission_briefing_media';
    protected $fillable = ['mission_id', 'name', 'path', 'mime'];
    protected $hidden = ['path'];
    protected $casts = ['name' => 'encrypted'];
}
