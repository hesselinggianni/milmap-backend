<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handmatig toegevoegde deelnemers (zonder account) als toewijzing van een taak.
 * Ze leven alleen als vrije tekst in de O-group (ogroup.participants), dus
 * bewaren we per taak een momentopname [{ name, role }] i.p.v. een user-relatie.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mission_tasks') && ! Schema::hasColumn('mission_tasks', 'assignee_participants')) {
            Schema::table('mission_tasks', function (Blueprint $t) {
                $t->json('assignee_participants')->nullable()->after('assigned_team_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mission_tasks') && Schema::hasColumn('mission_tasks', 'assignee_participants')) {
            Schema::table('mission_tasks', function (Blueprint $t) {
                $t->dropColumn('assignee_participants');
            });
        }
    }
};
