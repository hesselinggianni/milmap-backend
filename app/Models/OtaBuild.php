<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén gepubliceerde OTA-bundel (zip van dist/) voor de Capacitor-app.
 *
 * @see \App\Http\Controllers\OtaUpdateController
 */
class OtaBuild extends Model
{
    protected $fillable = [
        'platform',
        'channel',
        'version',
        'bundle_path',
        'checksum',
        'size',
        'min_native_version',
        'mandatory',
        'active',
    ];

    protected $casts = [
        'size' => 'integer',
        'mandatory' => 'boolean',
        'active' => 'boolean',
    ];
}
