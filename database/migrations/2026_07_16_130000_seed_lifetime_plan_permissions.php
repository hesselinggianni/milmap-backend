<?php

use App\Models\PlanPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standaardrechten voor het 'lifetime' (AppSumo) plan: volledige toegang op elke
 * module, net als Pro/Team. Zo krijgen verzilverde codes meteen alle functies;
 * de admin kan dit daarna verfijnen via "Plannen & rechten".
 *
 * Idempotent: bestaande (handmatig aangepaste) rijen worden niet overschreven.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (PlanPermission::MODULES as $module) {
            DB::table('plan_permissions')->updateOrInsert(
                ['plan' => 'lifetime', 'module' => $module],
                [
                    'can_view'   => true,
                    'can_create' => true,
                    'can_update' => true,
                    'can_delete' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('plan_permissions')->where('plan', 'lifetime')->delete();
    }
};
