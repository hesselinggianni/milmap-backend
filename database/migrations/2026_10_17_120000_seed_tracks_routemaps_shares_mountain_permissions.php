<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nieuwe betaalde modules: tracks, routekaarten, deellinks en het bergpakket.
 * Starter mag alleen kijken; Pro/Team/Lifetime krijgen alles. Idempotent:
 * bestaande (handmatig aangepaste) rijen blijven ongemoeid.
 */
return new class extends Migration
{
    private const MODULES = ['tracks', 'route_maps', 'shares', 'mountain_pack'];

    public function up(): void
    {
        $now = now();
        foreach (['starter', 'pro', 'team', 'lifetime'] as $plan) {
            $premium = $plan !== 'starter';
            foreach (self::MODULES as $module) {
                if (DB::table('plan_permissions')->where('plan', $plan)->where('module', $module)->exists()) {
                    continue;
                }
                DB::table('plan_permissions')->insert([
                    'plan' => $plan, 'module' => $module,
                    'can_view' => true, 'can_create' => $premium, 'can_update' => $premium, 'can_delete' => $premium,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('plan_permissions')->whereIn('module', self::MODULES)->delete();
    }
};
