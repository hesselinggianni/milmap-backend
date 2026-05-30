<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            // Bijlage: veiligheidsuitrusting, ontsnappingsroutes, coördinaten, telefoon, weer, neveneenheden
            $table->json('bijlage')->nullable()->after('meta');

            // Extra velden uit de bijlage die als top-level kolom nuttiger zijn
            $table->string('callsign')->nullable()->after('cs');
            $table->string('eenheid')->nullable()->after('callsign');
            $table->integer('sterkte')->nullable()->after('eenheid');
            $table->string('kaartblad')->nullable()->after('sterkte');
            $table->string('vtv_vta')->nullable()->after('kaartblad');
        });
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropColumn(['bijlage', 'callsign', 'eenheid', 'sterkte', 'kaartblad', 'vtv_vta']);
        });
    }
};
