<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectDevice extends Model {
    protected $fillable = ['user_id', 'installation_id', 'name', 'platform', 'last_seen_at', 'revoked_at'];
    protected $hidden = ['installation_id'];
    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
