<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Een fout die Stripe teruggaf (of waar de verbinding met Stripe op stukliep). */
class StripeError extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['handled' => 'boolean', 'context' => 'array', 'created_at' => 'datetime'];
}
