<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectAllowedDevice extends Model
{
    protected $fillable = ['organization_id', 'name', 'udid'];

    public function organization() { return $this->belongsTo(ConnectOrganization::class, 'organization_id'); }
}
