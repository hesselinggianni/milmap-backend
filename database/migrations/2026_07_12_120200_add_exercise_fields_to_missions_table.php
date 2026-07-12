<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maakt van een missie optioneel een "Oefening": game_mode schakelt de
 * oefening-features in (partijkeuze, regels-akkoord, doelen, scores) en
 * exercise_site_id koppelt de missie aan een terrein.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->enum('game_mode', ['standard', 'exercise'])
                ->default('standard')
                ->after('status');

            $table->uuid('exercise_site_id')->nullable()->after('game_mode');

            $table->foreign('exercise_site_id')
                ->references('id')->on('exercise_sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropForeign(['exercise_site_id']);
            $table->dropColumn(['exercise_site_id', 'game_mode']);
        });
    }
};
