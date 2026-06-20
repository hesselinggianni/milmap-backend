<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clothing_orders', function (Blueprint $table) {
            // Geheime token voor de "wijzig je bestelling"-link uit de mail.
            $table->string('edit_token', 64)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('clothing_orders', function (Blueprint $table) {
            $table->dropUnique(['edit_token']);
            $table->dropColumn('edit_token');
        });
    }
};
