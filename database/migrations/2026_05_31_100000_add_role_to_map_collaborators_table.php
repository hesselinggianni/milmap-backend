<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_collaborators', function (Blueprint $table) {
            $table->enum('role', ['viewer', 'editor', 'admin'])
                ->default('editor')
                ->after('added_by');
        });
    }

    public function down(): void
    {
        Schema::table('map_collaborators', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
