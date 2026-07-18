<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén link-in-bio-knop (milmap.nl/link-in-bio). Beheerd in de admin, publiek
 * uitgelezen door de Nuxt-pagina. Per-taal labels staan in `translations`.
 */
class BioLink extends Model
{
    protected $table = 'bio_links';

    protected $fillable = [
        'href',
        'icon',
        'variant',
        'translations',
        'enabled',
        'sort',
        'updated_by',
    ];

    protected $casts = [
        'translations' => 'array',
        'enabled'      => 'bool',
        'sort'         => 'int',
    ];

    /** Toegestane icoon-keys (matchen de SVG's in de Nuxt-pagina). */
    public const ICONS = ['globe', 'apple', 'android', 'partner', 'link'];

    /** Toegestane knop-stijlen. */
    public const VARIANTS = ['primary', 'dark', 'ghost'];
}
