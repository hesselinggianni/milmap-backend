<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MissionTaskTemplate extends Model
{
    use HasUuids;

    protected $table = 'mission_task_templates';

    protected $fillable = ['owner_id', 'name', 'description'];

    public function items()
    {
        return $this->hasMany(MissionTaskTemplateItem::class, 'template_id')
                    ->orderBy('order_index');
    }
}
