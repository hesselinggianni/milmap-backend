<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class ConnectOrder extends Model {
    use HasUuids;
    protected $guarded = ['id'];
    protected $hidden = ['request_id', 'billing_details'];
    protected $casts = ['billing_details'=>'encrypted:array','seats'=>'integer','term_months'=>'integer','unit_monthly_cents'=>'integer','total_cents'=>'integer'];
    public function organization() { return $this->belongsTo(ConnectOrganization::class,'organization_id'); }
    public function invoices() { return $this->hasMany(ConnectOrderInvoice::class,'order_id'); }
}
