<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SocialPost extends Model
{
    protected $fillable = [
        'title', 'description', 'link', 'image_path',
        'platforms', 'statuses', 'created_by',
    ];

    protected $casts = [
        'platforms' => 'array',
        'statuses'  => 'array',
    ];

    /** Publieke URL van het beeld (nodig voor Pinterest image_url + IG). */
    public function imageUrl(): ?string
    {
        return $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }
}
