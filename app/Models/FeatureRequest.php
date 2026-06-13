<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureRequest extends Model
{
    protected $table = 'feature_requests';

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'title',
        'description',
        'priority',
        'category',
        'status',
        'admin_note',
        'ip_address',
        'user_agent',
    ];

    /**
     * Optionele koppeling naar het MilMap-account met hetzelfde e-mailadres.
     * Wordt bij binnenkomst gezet (FeatureRequestController::store) en is null
     * voor externe inzenders zonder account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
