<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->timestamps();

            $table->foreign('owner_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
