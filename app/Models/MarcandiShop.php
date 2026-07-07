<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarcandiShop extends Model
{
    protected $fillable = ['slug', 'title', 'location', 'info', 'closes_at', 'pin', 'show_note', 'active'];

    protected $hidden = ['pin'];

    protected $casts = [
        'closes_at' => 'datetime',
        'show_note' => 'boolean',
        'active'    => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(MarcandiProduct::class, 'shop_id')->orderBy('sort');
    }

    public function orders()
    {
        return $this->hasMany(MarcandiOrder::class, 'shop_id');
    }

    /** Kan er nog besteld worden? (open + niet over de sluitingsdatum) */
    public function getIsOpenAttribute(): bool
    {
        if (!$this->active) {
            return false;
        }
        return !$this->closes_at || $this->closes_at->isFuture();
    }
}
