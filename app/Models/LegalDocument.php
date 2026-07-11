<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén (slug × taal)-rij van een juridisch document. Zie SeoOverride voor het
 * gedeelde platte-CMS-patroon; hier met een gestructureerde `sections`-JSON.
 */
class LegalDocument extends Model
{
    protected $table = 'legal_documents';

    protected $fillable = [
        'slug',
        'locale',
        'title',
        'intro',
        'sections',
        'effective_date',
        'published',
        'sort',
        'updated_by',
    ];

    protected $casts = [
        'sections'       => 'array',
        'effective_date' => 'date',
        'published'      => 'bool',
        'sort'           => 'int',
    ];

    /** Per-taal inhoudsvelden (de rest is document-breed). */
    public const CONTENT_FIELDS = ['title', 'intro', 'sections'];

    /** Document-brede velden (herhalen per taalrij van dezelfde slug). */
    public const DOC_FIELDS = ['effective_date', 'published', 'sort'];
}
