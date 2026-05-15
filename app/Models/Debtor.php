<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Debtor extends Model
{
    protected $fillable = [
        'phone', 'street_name', 'house_number', 'suffix', 'postal_code', 'city',
        'country', 'company_name', 'invoice_email', 'kvk', 'vat_number', 'website',
        'payment_term', 'payment_method', 'role', 'internal_notes', 'status',
        'user_id', 'debtor_code', 'account_manager_id',
    ];

    // Relatie met de gebruiker als accountmanager
    public function accountManager()
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    // Meerdere contactpersonen (veel-op-veel relatie)
    public function contacts()
    {
        return $this->belongsToMany(User::class, 'contact_debtor');
    }

    // Define the relationship with the User model
    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
