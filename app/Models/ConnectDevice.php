<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
class ConnectDevice extends Model {
    protected $fillable = ['user_id', 'organization_id', 'installation_id', 'vendor_id', 'model', 'os_version', 'app_version', 'name', 'platform', 'last_seen_at', 'revoked_at', 'update_requested_at', 'push_token', 'push_platform'];
    protected $hidden = ['installation_id', 'vendor_id', 'push_token'];
    protected $appends = ['is_connected'];
    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'update_requested_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function scopeConnected(Builder $q): Builder {
        return $q->whereNull('revoked_at')->whereExists(function ($tokens) {
            $tokens->selectRaw('1')->from('personal_access_tokens')
                ->whereColumn('personal_access_tokens.connect_device_id', 'connect_devices.id')
                ->whereColumn('personal_access_tokens.tokenable_id', 'connect_devices.user_id')
                ->where('personal_access_tokens.tokenable_type', User::class)
                ->where(fn ($t) => $t->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        });
    }
    public function getIsConnectedAttribute(): bool { return $this->exists && static::whereKey($this->id)->connected()->exists(); }
}
