<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén deelbare iOS-opdracht uit de galerij in de app (Meer → Siri & Opdrachten).
 * Beheerd in de admin, publiek uitgelezen door de app. Zie de migratie voor
 * waarom dit een iCloud-link is en geen bestand.
 */
class ShortcutTemplate extends Model
{
    protected $table = 'shortcut_templates';

    protected $fillable = [
        'icloud_url',
        'icon',
        'category',
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

    /** Toegestane icoon-keys (matchen de iconen in de app-galerij). */
    public const ICONS = ['shortcut', 'location', 'area', 'record', 'navigation', 'message', 'clock', 'sun'];

    /** Groepering in de galerij. */
    public const CATEGORIES = ['locatie', 'gebied', 'opname', 'navigatie', 'terrein', 'overig'];
}
