<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailFollowup extends Model
{
    protected $table = 'mail_followups';

    protected $fillable = [
        'campaign_id', 'parent_followup_id', 'template_key', 'category_id', 'delay_days',
        'condition', 'position', 'subject_override', 'active',
    ];

    protected $casts = [
        'parent_followup_id' => 'integer',
        'delay_days'         => 'integer',
        'position'           => 'integer',
        'active'             => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MailCampaign::class, 'campaign_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_followup_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_followup_id');
    }
}
