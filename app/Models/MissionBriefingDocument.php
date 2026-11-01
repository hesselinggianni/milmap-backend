<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class MissionBriefingDocument extends Model {
    use HasUuids;
    protected $fillable = ['id', 'mission_id', 'title', 'document', 'revision'];
    protected $casts = ['title' => 'encrypted', 'document' => 'encrypted:array', 'revision' => 'integer'];
}
