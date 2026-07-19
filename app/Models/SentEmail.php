<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén verzonden e-mail (universeel log, zie migratie create_sent_emails_table).
 * Rijen worden uitsluitend aangemaakt door de MessageSent-listener in
 * AppServiceProvider; handmatig schrijven is niet de bedoeling.
 */
class SentEmail extends Model
{
    protected $fillable = [
        'email', 'user_id', 'lead_id', 'token', 'subject', 'mailer', 'status',
        'sent_at', 'opened_at', 'open_count', 'click_count', 'last_click_url',
        'body_html',
    ];

    /** Body is zwaar — nooit meesturen in lijst-queries, alleen via show(). */
    protected $hidden = ['body_html'];

    protected $casts = [
        'sent_at'   => 'datetime',
        'opened_at' => 'datetime',
    ];

    public function clicks()
    {
        return $this->hasMany(SentEmailClick::class)->orderByDesc('clicked_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
