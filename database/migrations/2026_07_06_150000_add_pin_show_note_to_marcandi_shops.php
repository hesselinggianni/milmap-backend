<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marcandi_shops', function (Blueprint $table) {
            // Optionele pincode: zonder juiste pin geen toegang tot de shop.
            $table->string('pin', 20)->nullable()->after('closes_at');
            // Of het opmerking-veld op de bestelpagina getoond wordt.
            $table->boolean('show_note')->default(true)->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('marcandi_shops', function (Blueprint $table) {
            $table->dropColumn(['pin', 'show_note']);
        });
    }
};
