<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Beheerd label voor de taken-omgeving: een categorie of een platform
 * (kind = category|platform). Herbruikbaar en aan meerdere taken te koppelen.
 */
class TaskLabel extends Model
{
    protected $fillable = ['kind', 'name', 'color', 'position'];

    public const KINDS = ['category', 'platform'];

    public function todos(): BelongsToMany
    {
        return $this->belongsToMany(Todo::class, 'todo_task_label', 'task_label_id', 'todo_id');
    }

    public function toApiArray(): array
    {
        return [
            'id'    => $this->id,
            'kind'  => $this->kind,
            'name'  => $this->name,
            'color' => $this->color,
        ];
    }
}
