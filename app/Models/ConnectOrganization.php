<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ConnectOrganization extends Model
{
    protected $fillable = ['device_login_mode', 'contact_name', 'contact_email', 'billing_email', 'stripe_price_id', 'management_policy', 'restrict_to_allowed_devices', 'logo_path', 'login_background_path', 'name', 'status', 'seat_limit', 'monthly_price_cents', 'monthly_price_per_seat_cents', 'currency', 'starts_at', 'ends_at'];
    protected $attributes = ['restrict_to_allowed_devices' => true, 'device_login_mode' => 'udid'];
    protected $casts = ['management_policy' => 'array', 'restrict_to_allowed_devices' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'seat_limit' => 'integer', 'monthly_price_cents' => 'integer', 'monthly_price_per_seat_cents' => 'integer'];
    protected $hidden = ['logo_path', 'login_background_path', 'mdm_token_hash'];
    protected $appends = ['logo_url', 'login_background_url'];
    protected static function booted(): void
    {
        static::creating(fn ($o) => $o->uuid ??= (string) \Illuminate\Support\Str::uuid());
    }
    // Externe links gebruiken de uuid; het oude numerieke id blijft werken voor bestaande clients.
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) return parent::resolveRouteBinding($value, $field);
        return \Illuminate\Support\Str::isUuid((string) $value)
            ? $this->where('uuid', $value)->first()
            : $this->where('id', $value)->first();
    }
    private function publicUrl(?string $path): ?string
    {
        return $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
    }
    public function getLogoUrlAttribute(): ?string { return $this->publicUrl($this->attributes['logo_path'] ?? null); }
    public function getLoginBackgroundUrlAttribute(): ?string { return $this->publicUrl($this->attributes['login_background_path'] ?? null); }
    public function members() { return $this->hasMany(ConnectMember::class, 'organization_id'); }
    public function licenses() { return $this->hasMany(ConnectLicense::class, 'organization_id')->orderByDesc('starts_at')->orderByDesc('id'); }
    public function scopeWithActiveLicense(Builder $query): Builder
    {
        return $query->where('status', 'active')->where(function ($q) {
            $q->whereHas('licenses', fn ($l) => $l->active())
                ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('licenses')->where('starts_at', '<=', now())->where('ends_at', '>', now()));
        });
    }
    public function licensed(): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->licenses()->exists()) return $this->licenses()->active()->exists();
        return $this->starts_at && $this->starts_at <= now() && $this->ends_at && $this->ends_at > now();
    }
    private function activeLicenseTotal(string $column, $legacy): int
    {
        if ($this->relationLoaded('licenses')) {
            if ($this->licenses->isEmpty()) return (int) $legacy;
            return (int) $this->licenses->filter(fn ($l) => $l->starts_at <= now() && $l->ends_at > now())->sum($column);
        }
        return $this->licenses()->exists() ? (int) $this->licenses()->active()->sum($column) : (int) $legacy;
    }
    public function getEndsAtAttribute($value)
    {
        $end = $this->relationLoaded('licenses') ? $this->licenses->max('ends_at') : $this->licenses()->max('ends_at');
        return ($end || $value) ? $this->asDateTime($end ?: $value) : null;
    }
    public function getSeatLimitAttribute($value): int { return $this->activeLicenseTotal('seat_count', $value); }
    public function getMonthlyPriceCentsAttribute($value): int { return $this->activeLicenseTotal('monthly_price_cents', $value); }
    public function occupiedSeats(): int
    {
        return $this->members()->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())))->count();
    }
    /** Eén licentie per account én één per apparaat (vooraf toegestaan of geregistreerd). */
    public function deviceUnits(): int
    {
        $allowed = ConnectAllowedDevice::where('organization_id', $this->id)->pluck('udid')->map(fn ($u) => strtolower($u));
        $extra = ConnectDevice::where('organization_id', $this->id)->connected()
            ->whereIn('user_id', $this->members()->where('status', 'active')->select('user_id'))->get()
            ->reject(fn ($d) => $d->vendor_id && $allowed->contains(strtolower($d->vendor_id)))->count();
        return $allowed->count() + $extra;
    }
    public function usedLicenses(): int { return $this->occupiedSeats() + $this->deviceUnits(); }
    public function occupiedDevices(): int
    {
        return ConnectDevice::where('organization_id', $this->id)->connected()
            ->whereIn('user_id', $this->members()->where('status', 'active')->select('user_id'))->count();
    }
}
