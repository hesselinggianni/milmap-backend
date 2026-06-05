<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // 'text'     — plain chat message (E2EE sealed box)
            // 'location' — GPS coordinates card (encryption=none, JSON payload)
            // 'mission'  — mission link card   (encryption=none, JSON payload)
            $table->enum('type', ['text', 'location', 'mission'])
                  ->default('text')
                  ->after('encryption');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
