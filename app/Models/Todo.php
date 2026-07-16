<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'priority',
        'assignee',
        'app_version',
        'deadline_week',
        'deadline_year',
        'position',
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
        'deadline_week'     => 'integer',
        'deadline_year'     => 'integer',
        'position'          => 'integer',
        'status_changed_at' => 'datetime',
        'completed_at'      => 'datetime',
    ];

    /** Workflow-fases (kanban-kolommen). */
    public const STATUSES = [
        'backlog', 'voorbereiding', 'wachtrij', 'bezig',
        'review', 'feedback', 'beta', 'productie',
    ];

    /** Prioriteiten. */
    public const PRIORITIES = ['laag', 'normaal', 'hoog', 'urgent'];

    /**
     * Legacy queue-status (deploy-app) → nieuwe workflow-fase, en omgekeerd.
     * De queue-runner in de deploy-app spreekt nog pending/queued/running/
     * done/failed; deze mappings vertalen aan de rand zodat beide werelden
     * samenwerken tot de deploy-app is bijgewerkt.
     */
    public const LEGACY_TO_STATUS = [
        'pending' => 'backlog',
        'queued'  => 'wachtrij',
        'running' => 'bezig',
        'done'    => 'review',
        'failed'  => 'feedback',
    ];

    public const STATUS_TO_LEGACY = [
        'backlog'       => 'pending',
        'voorbereiding' => 'pending',
        'wachtrij'      => 'queued',
        'bezig'         => 'running',
        'review'        => 'done',
        'feedback'      => 'failed',
        'beta'          => 'done',
        'productie'     => 'done',
    ];

    public static function legacyStatus(?string $status): string
    {
        return self::STATUS_TO_LEGACY[$status] ?? 'pending';
    }

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

    /** Gekoppelde labels (categorieën + platforms). */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TaskLabel::class, 'todo_task_label', 'todo_id', 'task_label_id');
    }

    /** Naam van de toegewezene ('Claude', een admin-naam, of null). */
    public function assigneeName(): ?string
    {
        if (! $this->assignee) return null;
        if ($this->assignee === 'claude') return 'Claude';
        return optional(User::find($this->assignee))->full_name ?? $this->assignee;
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
            'priority'        => $this->priority,
            'assignee'        => $this->assignee,
            'assigneeName'    => $this->assigneeName(),
            'appVersion'      => $this->app_version,
            'deadlineWeek'    => $this->deadline_week,
            'deadlineYear'    => $this->deadline_year,
            'position'        => $this->position,
            'labels'          => $this->relationLoaded('labels')
                ? $this->labels->map->toApiArray()->values()
                : [],
            'source'          => $this->source,
            'lastExit'        => $this->last_exit,
            'followups'       => $this->followups ?? [],
            'createdBy'       => $this->creator?->full_name,
            'createdAt'       => optional($this->created_at)->toIso8601String(),
            'statusChangedAt' => optional($this->status_changed_at)->toIso8601String(),
            'completedAt'     => optional($this->completed_at)->toIso8601String(),
        ];
    }

    /**
     * Deploy-app-vorm: identiek aan toApiArray maar met de status vertaald naar
     * de legacy queue-status (pending/queued/running/done/failed) zodat de
     * bestaande queue-runner blijft werken.
     */
    public function toDeployArray(): array
    {
        $arr = $this->toApiArray();
        $arr['status'] = self::legacyStatus($this->status);
        $arr['workflowStatus'] = $this->status; // de echte fase, voor nieuwere clients
        return $arr;
    }
}
