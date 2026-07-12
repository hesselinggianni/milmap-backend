<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Terrein-rol van een gebruiker (commander | controller | player) — de standaard
 * die per oefening te overschrijven is.
 */
class SiteMember extends Model
{
    use HasUuids;

    protected $table = 'site_members';

    protected $fillable = [
        'site_id',
        'user_id',
        'role',
        'added_by',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ExerciseSite::class, 'site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
