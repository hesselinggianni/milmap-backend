<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koppel een taak optioneel aan een O-group fase/subfase van een template-
 * gebaseerde missie. Waarde = "<phaseKey>" (hoofdfase) of "<phaseKey>/<subKey>"
 * (subfase). Null voor gewone taken. Geïndexeerd voor filteren per fase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_tasks', function (Blueprint $t) {
            $t->string('phase_key', 96)->nullable()->after('parent_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mission_tasks', function (Blueprint $t) {
            $t->dropIndex(['phase_key']);
            $t->dropColumn('phase_key');
        });
    }
};
