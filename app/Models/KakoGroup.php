<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Persoonlijke deelnemersgroep voor KAKO-trainingen; leden: [{ id, name, device_id? }]. */
class KakoGroup extends Model
{
    protected $fillable = ['user_id', 'name', 'members'];

    protected $casts = ['members' => 'array'];
}
