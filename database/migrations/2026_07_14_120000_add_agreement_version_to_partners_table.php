<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versie van de partnerovereenkomst die de partner heeft geaccepteerd, zodat
 * bij latere tekstwijzigingen vaststaat wélke afspraken zijn aanvaard
 * (discussie-vermijding; zie ook het wijzigingsbeding in de overeenkomst).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('agreement_version', 20)->nullable()->after('agreement_ip');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('agreement_version');
        });
    }
};
