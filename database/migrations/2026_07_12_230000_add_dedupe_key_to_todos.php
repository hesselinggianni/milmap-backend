<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De sales-taken-generator moet per signaal-type één levende taak kunnen
     * bijhouden i.p.v. bij elke run een duplicaat aan te maken. `dedupe_key`
     * markeert automatisch gegenereerde taken met een stabiele sleutel
     * (bv. "sales:trials-expiring"); handmatige taken laten 'm leeg.
     */
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('dedupe_key', 120)->nullable()->index()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
