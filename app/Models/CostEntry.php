<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostEntry extends Model
{
    protected $table = 'cost_entries';

    protected $fillable = [
        'label', 'direction', 'amount', 'recurrence', 'category',
        'incurred_on', 'ends_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'incurred_on' => 'date',
        'ends_on'     => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
