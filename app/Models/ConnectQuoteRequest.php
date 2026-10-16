<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectQuoteRequest extends Model {
    protected $fillable = ['user_id','company_name','contact_name','email','billing_email','phone','seat_count','purpose','requirements','status'];
    protected $casts = ['seat_count' => 'integer'];
}
