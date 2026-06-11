<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MissionTaskTemplateItem extends Model
{
    use HasUuids;

    protected $table = 'mission_task_template_items';

    protected $fillable = ['template_id', 'title', 'description', 'order_index'];

    public function template()
    {
        return $this->belongsTo(MissionTaskTemplate::class, 'template_id');
    }
}
