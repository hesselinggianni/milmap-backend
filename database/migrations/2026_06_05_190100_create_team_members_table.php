<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');

            // The member's e-mail is always stored (so we can invite people who
            // do not yet have a MilMap account). user_id links to an existing
            // account when there is one.
            $table->string('email', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('added_by');

            $table->timestamps();

            $table->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->unique(['team_id', 'email'], 'uniq_team_member_email');
            $table->index('team_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
