<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A persisted MEDEVAC 9-liner. See the create_nine_lines_table migration for
 * the privacy rationale (line values are stored as plaintext JSON on purpose).
 */
class NineLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'mission_id',
        'user_id',
        'ref',
        'title',
        'mode',
        'exercise',
        'lines',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            // MEDEVAC-inhoud (letsel, aantal slachtoffers) = gezondheidsdata,
            // bijzondere categorie onder AVG art. 9. Versleuteld at rest; de
            // kolom is daarom LONGTEXT i.p.v. json (MySQL valideert json en
            // weigert een versleutelde string). Zie de migratie
            // 2026_08_10_200000_encrypt_sensitive_operational_data.
            'lines'       => 'encrypted:array',
            'exercise'    => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function mission()
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function revisions()
    {
        return $this->hasMany(NineLineRevision::class)->orderBy('id', 'desc');
    }
}
