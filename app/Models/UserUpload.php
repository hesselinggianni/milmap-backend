<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eén regel in het opslag-grootboek: een server-side geüpload bestand,
 * toegerekend aan de gebruiker die het uploadde. Alleen metadata.
 *
 * @see StatsController voor de optelling per gebruiker.
 * @see \Database\Migrations user_uploads
 */
class UserUpload extends Model
{
    use SoftDeletes;

    protected $table = 'user_uploads';

    protected $fillable = [
        'user_id',
        'kind',
        'disk',
        'path',
        'size',
        'mime',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Leg een upload vast. Faalt nooit hard — opslag-boekhouding mag een
     * geslaagde upload niet alsnog laten klappen.
     */
    public static function record(int $userId, string $path, int $size, ?string $mime = null, string $kind = 'chat', string $disk = 'public'): void
    {
        try {
            static::create([
                'user_id' => $userId,
                'kind'    => $kind,
                'disk'    => $disk,
                'path'    => $path,
                'size'    => max(0, $size),
                'mime'    => $mime,
            ]);
        } catch (\Throwable $e) {
            // Best-effort grootboek; stil falen.
        }
    }
}
