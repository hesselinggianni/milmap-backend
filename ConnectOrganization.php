<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectOrganization extends Model {
    protected $fillable = ['name', 'status', 'seat_limit', 'monthly_price_cents', 'monthly_price_per_seat_cents', 'currency', 'starts_at', 'ends_at'];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'seat_limit' => 'integer', 'monthly_price_cents' => 'integer', 'monthly_price_per_seat_cents' => 'integer'];
    public function members() { return $this->hasMany(ConnectMember::class, 'organization_id'); }
    public function licensed(): bool { return $this->status === 'active' && $this->starts_at <= now() && $this->ends_at > now(); }
    public function occupiedSeats(): int {
        return $this->members()->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())))->count();
    }
}
