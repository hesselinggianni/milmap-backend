<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ConnectLicense extends Model
{
    protected $fillable = ['organization_id', 'name', 'seat_count', 'monthly_price_cents', 'currency', 'starts_at', 'ends_at', 'source_reference', 'is_trial'];
    protected $hidden = ['source_reference'];
    protected $casts = ['seat_count' => 'integer', 'is_trial' => 'boolean', 'monthly_price_cents' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    public function organization() { return $this->belongsTo(ConnectOrganization::class, 'organization_id'); }
    /** Tijdelijke toegang tot de factuur is betaald (ConnectBilling::GRACE_PREFIX): geen betaalde licentie. */
    public function hasGraceReference(): bool { return str_starts_with((string) $this->source_reference, \App\Services\ConnectBilling::GRACE_PREFIX); }
    public function scopeActive(Builder $query): Builder { return $query->where('starts_at', '<=', now())->where('ends_at', '>', now()); }
}
