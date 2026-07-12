<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registreert welke taken-template op een missie is toegepast
     * ({id, name, applied_at}) zodat de UI die kan tonen/openen.
     */
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->json('template_info')->nullable()->after('ogroup');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('template_info');
        });
    }
};
