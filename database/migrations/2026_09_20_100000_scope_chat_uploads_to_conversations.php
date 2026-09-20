<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_uploads', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable()->after('user_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_uploads', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropColumn('conversation_id');
        });
    }
};
