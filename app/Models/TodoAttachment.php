<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Een bijlage bij een taak (afbeelding of bestand). Opgeslagen op de 'public'
 * disk zodat de deploy-app het bestand kan downloaden en aan de Claude-prompt
 * kan meegeven.
 */
class TodoAttachment extends Model
{
    protected $fillable = ['todo_id', 'path', 'original_name', 'mime', 'size'];

    protected $casts = ['size' => 'integer'];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'todo_id');
    }

    public function url(): string
    {
        try {
            return Storage::disk('public')->url($this->path);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function toApiArray(): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->original_name,
            'url'   => $this->url(),
            'mime'  => $this->mime,
            'size'  => $this->size,
            'image' => $this->isImage(),
        ];
    }
}
