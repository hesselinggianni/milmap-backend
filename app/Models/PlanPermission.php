<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén rij = de CRUD-rechten die een abonnement (plan) heeft op een module
 * (feature-sleutel, bv. 'missions', 'chat'). Beheerd via het admin-scherm
 * "Plannen & rechten"; gelezen door User::hasFeature() en RequiresPremium.
 */
class PlanPermission extends Model
{
    protected $fillable = [
        'plan', 'module', 'can_view', 'can_create', 'can_update', 'can_delete',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_create' => 'boolean',
        'can_update' => 'boolean',
        'can_delete' => 'boolean',
    ];

    /** Alle bekende modules die door de app gated worden. */
    public const MODULES = [
        'missions', 'chat', 'nine_liner', 'unlimited_maps', 'weather',
        'offline_maps', 'area_markers', 'route_export', 'teams',
        'team_shared_maps', 'live_location', 'exercise',
    ];

    /** Abonnementen waarvoor rechten instelbaar zijn. */
    public const PLANS = ['starter', 'pro', 'team'];
}
