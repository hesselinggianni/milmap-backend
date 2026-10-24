<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Een missie kan een eigen status hebben (de gebruiker maakt die in de app aan; de missie bewaart alleen de sleutel).
 * De kolom was sinds 2026_06_20 een ENUM met zeven vaste waarden: een eigen status gaf dus een databasefout.
 * Bestaande waarden blijven staan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->string('status', 64)->default('planning')->change();
        });
    }

    public function down(): void
    {
        // Terug naar de vaste lijst: eigen statussen kunnen daar niet in en vallen terug op 'planning'.
        $known = ['draft', 'planning', 'review', 'approved', 'active', 'complete', 'archived'];
        DB::table('missions')->whereNotIn('status', $known)->update(['status' => 'planning']);
        Schema::table('missions', function (Blueprint $table) use ($known) {
            $table->enum('status', $known)->default('planning')->change();
        });
    }
};
