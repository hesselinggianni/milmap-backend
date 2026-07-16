<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén regel op de taak-tijdlijn: een comment of een automatische wijziging.
 */
class TodoActivity extends Model
{
    public $timestamps = false; // alleen created_at

    protected $fillable = ['todo_id', 'kind', 'author', 'body', 'meta', 'created_at'];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'todo_id');
    }

    public function toApiArray(): array
    {
        return [
            'id'        => $this->id,
            'kind'      => $this->kind,
            'author'    => $this->author,
            'body'      => $this->body,
            'meta'      => $this->meta,
            'createdAt' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
