<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subtaken: een taak kan een ouder-taak hebben (parent_id). Top-level taken
 * hebben parent_id = null. Volgorde blijft per sibling-groep via order_index.
 *
 * nullOnDelete is een vangnet — de controller verwijdert kinderen expliciet
 * wanneer hun ouder wordt verwijderd, zodat we geen wees-subtaken houden.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mission_tasks', function (Blueprint $t) {
            $t->uuid('parent_id')->nullable()->after('mission_id');
            $t->foreign('parent_id')->references('id')->on('mission_tasks')->nullOnDelete();
            $t->index(['mission_id', 'parent_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::table('mission_tasks', function (Blueprint $t) {
            $t->dropForeign(['parent_id']);
            $t->dropIndex(['mission_id', 'parent_id', 'order_index']);
            $t->dropColumn('parent_id');
        });
    }
};
