<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Aan: Connect-rechten gelden alleen op apparaten uit de toegestane lijst; op alle
        // andere apparaten valt het lid terug op zijn eigen abonnement.
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->boolean('restrict_to_allowed_devices')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->dropColumn('restrict_to_allowed_devices');
        });
    }
};
