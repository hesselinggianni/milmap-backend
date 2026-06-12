<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prioriteit per taak. Vier waarden — urgent / high / normal / low — met
 * `normal` als default. Korte varchar i.p.v. een echte enum zodat we
 * makkelijk een 5e waarde kunnen toevoegen zonder migration-drama later.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mission_tasks') && ! Schema::hasColumn('mission_tasks', 'priority')) {
            Schema::table('mission_tasks', function (Blueprint $t) {
                $t->string('priority', 16)->default('normal')->after('status');
                $t->index(['mission_id', 'priority']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mission_tasks') && Schema::hasColumn('mission_tasks', 'priority')) {
            Schema::table('mission_tasks', function (Blueprint $t) {
                $t->dropIndex(['mission_id', 'priority']);
                $t->dropColumn('priority');
            });
        }
    }
};
