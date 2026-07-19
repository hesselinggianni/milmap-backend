<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eén klik op een link/knop in een verzonden mail (zie sent_email_clicks). */
class SentEmailClick extends Model
{
    protected $fillable = ['sent_email_id', 'url', 'label', 'clicked_at'];

    protected $casts = ['clicked_at' => 'datetime'];

    public function sentEmail()
    {
        return $this->belongsTo(SentEmail::class);
    }
}
