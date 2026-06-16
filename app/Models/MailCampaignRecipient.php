<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailCampaignRecipient extends Model
{
    protected $table = 'mail_campaign_recipients';

    protected $fillable = [
        'campaign_id', 'email', 'user_id', 'lead_id', 'name', 'source',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MailCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
