<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailCampaign extends Model
{
    protected $table = 'mail_campaigns';

    protected $fillable = [
        'name', 'description', 'category_id', 'template_key', 'status',
        'default_locale', 'created_by', 'recipients_built_at', 'scheduled_at', 'sent_at',
    ];

    protected $casts = [
        'recipients_built_at' => 'datetime',
        'scheduled_at'        => 'datetime',
        'sent_at'             => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MailCategory::class, 'category_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailCampaignRecipient::class, 'campaign_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(MailSend::class, 'campaign_id');
    }
}
