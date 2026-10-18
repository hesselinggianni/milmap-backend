<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_limit_events', function (Blueprint $table) {
            // Leesbare reden van de 429 (welke limiet, hoeveel per hoelang) voor
            // admin → Te veel verzoeken.
            $table->string('reason', 160)->nullable()->after('limiter');
        });
    }

    public function down(): void
    {
        Schema::table('rate_limit_events', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
