<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $table = 'leads';

    protected $fillable = [
        'email', 'source', 'utm_source', 'ip_address', 'user_agent', 'platform', 'language', 'notified_at', 'converted_at',
        'use_case', 'interests', 'funnel_step',
    ];

    protected $casts = [
        'notified_at'  => 'datetime',
        'converted_at' => 'datetime',
        'interests'    => 'array',
        'funnel_step'  => 'integer',
    ];

    /**
     * Markeer elke nog-niet-geconverteerde lead met dit e-mailadres als
     * geconverteerd. Aanroepen zodra er daadwerkelijk een account met dat
     * adres ontstaat (registratie of guest-checkout na betaling) — stopt ook
     * de lead-nurture-mails (zie ProcessMailFollowups' converted-check).
     */
    public static function markConverted(string $email): void
    {
        static::where('email', mb_strtolower(trim($email)))
            ->whereNull('converted_at')
            ->update(['converted_at' => now()]);
    }
}
