<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mission_collaborators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('added_by');

            // Permissions: viewer (read-only), editor (edit), admin (edit + manage)
            $table->enum('role', ['viewer', 'editor', 'admin'])->default('editor');

            // pending (awaiting acceptance) | accepted (has access)
            $table->enum('status', ['pending', 'accepted'])->default('pending');

            $table->string('invitation_token', 64)->nullable()->unique();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->foreign('mission_id')
                ->references('id')
                ->on('missions')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->unique(['mission_id', 'user_id'], 'unique_mission_user');
            $table->index(['mission_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('invitation_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_collaborators');
    }
};
