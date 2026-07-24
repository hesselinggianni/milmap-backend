<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            // MapController::index()/myMaps() doen WHERE owner_id = ? ORDER BY
            // created_at DESC zonder index — bij groei full table scan + sort.
            // Composite dekt zowel het filter als de sort in één indexlookup.
            $table->index(['owner_id', 'created_at'], 'maps_owner_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropIndex('maps_owner_id_created_at_index');
        });
    }
};
