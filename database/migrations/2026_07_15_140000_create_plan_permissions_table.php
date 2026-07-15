<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Per-abonnement rechten per module (view/create/update/delete). Vervangt de
 * binaire PREMIUM_FEATURES-poort: elk (plan, module)-paar krijgt nu zijn eigen
 * CRUD-vlaggen, instelbaar via het admin-scherm "Plannen & rechten".
 *
 * Seed hieronder = exacte huidige gedrag (starter = alles uit, pro/team =
 * alles aan) zodat deploy geen regressie geeft; admins kunnen 'm daarna
 * verfijnen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('plan');   // starter | pro | team
            $table->string('module'); // missions | chat | nine_liner | ...
            $table->boolean('can_view')->default(true);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();
            $table->unique(['plan', 'module']);
        });

        $modules = [
            'missions', 'chat', 'nine_liner', 'unlimited_maps', 'weather',
            'offline_maps', 'area_markers', 'route_export', 'teams',
            'team_shared_maps', 'live_location', 'exercise',
        ];

        $now = now();
        $rows = [];
        foreach (['starter', 'pro', 'team'] as $plan) {
            $premium = $plan !== 'starter';
            foreach ($modules as $module) {
                $rows[] = [
                    'plan' => $plan,
                    'module' => $module,
                    'can_view' => true,
                    'can_create' => $premium,
                    'can_update' => $premium,
                    'can_delete' => $premium,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('plan_permissions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_permissions');
    }
};
