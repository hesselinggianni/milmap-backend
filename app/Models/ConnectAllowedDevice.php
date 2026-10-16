<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectAllowedDevice extends Model
{
    protected $hidden = ['binding_hash'];

    protected $fillable = ['organization_id', 'name', 'udid', 'platform'];

    public function organization() { return $this->belongsTo(ConnectOrganization::class, 'organization_id'); }
}
