<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmDeal extends Model
{
    protected $table = 'crm_deals';

    protected $fillable = [
        'title',
        'contact_name', 'contact_email', 'contact_phone', 'contact_role',
        'company_name', 'company_website', 'company_address', 'company_size',
        'value', 'currency', 'category', 'tags',
        'stage', 'position', 'status', 'goal', 'notes',
        'last_contact_at', 'next_action_at',
        'user_id', 'partner_id', 'created_by',
    ];

    protected $casts = [
        'tags'            => 'array',
        'value'           => 'integer',
        'position'        => 'integer',
        'last_contact_at' => 'datetime',
        'next_action_at'  => 'datetime',
    ];

    /** Pijplijn-fases (vaste set kolommen). */
    public const STAGES = [
        ['key' => 'lead',        'label' => 'Lead',          'color' => '#94a3b8'],
        ['key' => 'contacted',   'label' => 'Benaderd',      'color' => '#38bdf8'],
        ['key' => 'demo',        'label' => 'Demo',          'color' => '#a78bfa'],
        ['key' => 'negotiation', 'label' => 'Onderhandeling','color' => '#fbbf24'],
        ['key' => 'won',         'label' => 'Gewonnen',      'color' => '#22c55e'],
        ['key' => 'lost',        'label' => 'Verloren',      'color' => '#ef4444'],
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'deal_id')->orderByDesc('happened_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function demoUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * Stoplicht voor prioriteit: rood = actie te laat / lang geen contact,
     * oranje = opvolging komt eraan, groen = recent contact / niets te doen.
     * Gewonnen/verloren deals krijgen 'none'.
     */
    public function health(): string
    {
        if (in_array($this->status, ['won', 'lost'], true)) {
            return 'none';
        }

        $now = now();

        // Expliciete vervolgactie is leidend.
        if ($this->next_action_at) {
            if ($this->next_action_at->lt($now)) return 'red';
            if ($this->next_action_at->lte($now->copy()->addDays(2))) return 'amber';
            return 'green';
        }

        // Anders op basis van laatste contact.
        if (! $this->last_contact_at) {
            // Nog nooit benaderd: na 7 dagen rood, daarvoor oranje.
            return $this->created_at && $this->created_at->lt($now->copy()->subDays(7)) ? 'red' : 'amber';
        }

        $days = $this->last_contact_at->diffInDays($now);
        if ($days >= 14) return 'red';
        if ($days >= 7)  return 'amber';
        return 'green';
    }

    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'contact_name'    => $this->contact_name,
            'contact_email'   => $this->contact_email,
            'contact_phone'   => $this->contact_phone,
            'contact_role'    => $this->contact_role,
            'company_name'    => $this->company_name,
            'company_website' => $this->company_website,
            'company_address' => $this->company_address,
            'company_size'    => $this->company_size,
            'value'           => (int) $this->value,
            'currency'        => $this->currency,
            'category'        => $this->category,
            'tags'            => $this->tags ?? [],
            'stage'           => $this->stage,
            'position'        => (int) $this->position,
            'status'          => $this->status,
            'goal'            => $this->goal,
            'notes'           => $this->notes,
            'last_contact_at' => optional($this->last_contact_at)->toIso8601String(),
            'next_action_at'  => optional($this->next_action_at)->toIso8601String(),
            'health'          => $this->health(),
            'user_id'         => $this->user_id,
            'partner_id'      => $this->partner_id,
            'has_demo'        => (bool) $this->user_id,
            'is_partner'      => (bool) $this->partner_id,
            'created_at'      => optional($this->created_at)->toIso8601String(),
        ];
    }
}
