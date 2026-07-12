<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Een deploy-todo: een door Claude uit te voeren taak ("ask-claude") of een
 * los actiepunt. De deploy-app draaide deze lijst eerst lokaal (todos.json);
 * nu is dit model de gedeelde bron van waarheid voor zowel de deploy-app als
 * het MilMap-admin paneel.
 */
class Todo extends Model
{
    protected $table = 'todos';

    /** String-PK (base36), niet auto-increment. */
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'repo',
        'mode',
        'status',
        'source',
        'dedupe_key',
        'last_exit',
        'followups',
        'created_by',
        'status_changed_at',
        'completed_at',
    ];

    protected $casts = [
        'followups'         => 'array',
        'last_exit'         => 'integer',
        'status_changed_at' => 'datetime',
        'completed_at'      => 'datetime',
    ];

    /** Toegestane statussen (gedeeld met de deploy-app queue-runner). */
    public const STATUSES = ['pending', 'queued', 'running', 'done', 'failed'];

    /**
     * Geef elke nieuwe todo automatisch een base36-id in hetzelfde formaat als
     * de deploy-app (Date.now().toString(36) + random), tenzij de client er al
     * één meestuurt (zodat bestaande deploy-app-id's bewaard blijven).
     */
    protected static function booted(): void
    {
        static::creating(function (Todo $todo) {
            if (empty($todo->id)) {
                $todo->id = base_convert((string) (now()->valueOf()), 10, 36)
                    . Str::lower(Str::random(5));
            }
        });
    }

    /** Admin die de taak aanmaakte (null voor deploy-app/verwijderd account). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Canonieke JSON-vorm. Bewust camelCase zodat de deploy-app (die deze keys
     * al gebruikte in todos.json) en de nieuwe admin-UI exact dezelfde shape
     * delen — één contract voor beide clients.
     */
    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'description'     => $this->description,
            'repo'            => $this->repo,
            'mode'            => $this->mode,
            'status'          => $this->status,
            'source'          => $this->source,
            'lastExit'        => $this->last_exit,
            'followups'       => $this->followups ?? [],
            'createdBy'       => $this->creator?->full_name,
            'createdAt'       => optional($this->created_at)->toIso8601String(),
            'statusChangedAt' => optional($this->status_changed_at)->toIso8601String(),
            'completedAt'     => optional($this->completed_at)->toIso8601String(),
        ];
    }
}
