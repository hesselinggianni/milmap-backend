<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('map_collaborators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('map_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('added_by');

            // Status: pending (awaiting acceptance), accepted (has access)
            $table->enum('status', ['pending', 'accepted'])->default('pending');

            // Token for email invitation acceptance (64 chars, unique)
            $table->string('invitation_token', 64)->nullable()->unique();

            // Timestamps for invitation lifecycle
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // Standard timestamps
            $table->timestamps();

            // Foreign keys
            $table->foreign('map_id')
                ->references('id')
                ->on('maps')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            // Indexes
            $table->unique(['map_id', 'user_id'], 'unique_map_user');
            $table->index(['map_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('invitation_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('map_collaborators');
    }
};
