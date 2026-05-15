<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->after('id')
                ->constrained('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropColumn('owner_id');
        });
    }
};