<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antwoorden uit de start-funnel bij de lead bewaren.
 *
 * Stap 2 vraagt waarvóór iemand MilMap gaat gebruiken en stap 3 laat zien wat
 * je krijgt; die keuzes bleven tot nu toe in de browser hangen en gingen bij
 * het afronden van de trechter verloren. Ze staan hier bij de lead (en niet
 * bij de user) omdat ze al bekend zijn vóórdat er een account bestaat — de
 * registratiemail zoekt ze er straks op e-mailadres bij.
 *
 * `funnel_step` houdt de HOOGST bereikte stap vast (1..4) — niet de laatste.
 * Daarmee zie je in de admin waar mensen afhaken; terugbladeren in de trechter
 * mag die score dus nooit verlagen (zie LeadController::store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('use_case')->nullable()->after('language');
            $table->json('interests')->nullable()->after('use_case');
            $table->unsignedTinyInteger('funnel_step')->nullable()->after('interests');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['use_case', 'interests', 'funnel_step']);
        });
    }
};
