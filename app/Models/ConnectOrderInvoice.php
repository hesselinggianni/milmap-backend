<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectOrderInvoice extends Model {
    protected $guarded = ['id'];
    protected $casts = ['total_cents'=>'integer','period_start'=>'datetime','period_end'=>'datetime','paid_at'=>'datetime'];
}
