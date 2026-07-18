<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén regel in de wijzigingsgeschiedenis van een gemarkeerd gebied (report).
 */
class ReportRevision extends Model
{
    protected $fillable = ['report_id', 'user_id', 'action', 'changes'];

    protected $casts = ['changes' => 'array'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            // getAttribute() i.p.v. $this->changes: die laatste botst met
            // Eloquent's interne $changes-property (dirty-tracking) en geeft [].
            'changes'    => $this->getAttribute('changes'),
            'user'       => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->full_name,
            ] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
