<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectMember extends Model {
    protected $fillable = ['permissions', 'organization_id', 'user_id', 'first_name', 'last_name', 'email', 'role', 'status', 'tags', 'token_hash', 'expires_at', 'accepted_at', 'mail_sent_at'];
    protected $hidden = ['token_hash'];
    protected $appends = ['device_account'];
    protected $casts = ['permissions' => 'array', 'tags' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'mail_sent_at' => 'datetime'];
    public function organization() { return $this->belongsTo(ConnectOrganization::class, 'organization_id'); }
    public function user() { return $this->belongsTo(User::class); }
    /** Het technische account van een apparaat (udid@connect.milmap.nl): geen gebruiker, de licentie hoort bij het apparaat. */
    public function getDeviceAccountAttribute(): bool { return \App\Services\ConnectPersonalBillingGuard::isDeviceEmail($this->email); }
    public function scopeExcludingDeviceAccounts($query) { return $query->whereRaw('LOWER(email) NOT LIKE ?', ['%@'.\App\Services\ConnectPersonalBillingGuard::DEVICE_DOMAIN]); }
}
