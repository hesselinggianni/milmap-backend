<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectMember extends Model {
    protected $fillable = ['permissions', 'organization_id', 'user_id', 'first_name', 'last_name', 'email', 'role', 'status', 'tags', 'token_hash', 'expires_at', 'accepted_at', 'mail_sent_at'];
    protected $hidden = ['token_hash'];
    protected $casts = ['permissions' => 'array', 'tags' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'mail_sent_at' => 'datetime'];
    public function organization() { return $this->belongsTo(ConnectOrganization::class, 'organization_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
