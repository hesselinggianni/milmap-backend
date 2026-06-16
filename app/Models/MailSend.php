<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailSend extends Model
{
    protected $table = 'mail_sends';

    protected $fillable = [
        'campaign_id', 'followup_id', 'email', 'user_id', 'lead_id', 'name',
        'language', 'template_key', 'category_id', 'subject', 'status', 'token',
        'position', 'sent_at', 'opened_at', 'open_count', 'failure_reason',
    ];

    protected $casts = [
        'sent_at'    => 'datetime',
        'opened_at'  => 'datetime',
        'open_count' => 'integer',
        'position'   => 'integer',
        'followup_id' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MailCampaign::class, 'campaign_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MailCategory::class, 'category_id');
    }
}
