<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class ConnectPendingAddition extends Model {
    use HasUuids;
    protected $guarded = ['id'];
    protected $hidden = ['rows'];
    protected $casts = ['rows' => 'encrypted:array', 'result' => 'array', 'row_count' => 'integer', 'seats_needed' => 'integer', 'processed_at' => 'datetime'];
    public function organization() { return $this->belongsTo(ConnectOrganization::class, 'organization_id'); }
    public function order() { return $this->belongsTo(ConnectOrder::class, 'order_id'); }
}
