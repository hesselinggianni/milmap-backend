<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoOverride extends Model
{
    protected $table = 'seo_overrides';

    protected $fillable = [
        'page_key',
        'locale',
        'title',
        'description',
        'og_title',
        'og_description',
        'og_image',
        'canonical',
        'robots',
        'updated_by',
    ];

    /** De SEO-velden (zonder routing/meta-kolommen). */
    public const FIELDS = [
        'title', 'description', 'og_title', 'og_description', 'og_image', 'canonical', 'robots',
    ];
}
