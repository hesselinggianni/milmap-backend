<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One audit-log entry for a NineLine. `changes` holds the field-level diff for
 * 'updated' actions; it is null for 'created' / 'archived' / 'unarchived'.
 */
class NineLineRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'nine_line_id',
        'user_id',
        'action',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function nineLine()
    {
        return $this->belongsTo(NineLine::class);
    }
}
