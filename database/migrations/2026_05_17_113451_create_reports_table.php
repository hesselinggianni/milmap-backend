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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('map_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('category'); // threat, hazard, salute
            $table->string('type'); // infantry, tank, drone, etc.
            $table->string('subtype')->nullable(); // fire, move, etc.
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('urgency')->nullable(); // green, yellow, red
            $table->string('timing')->nullable(); // now, 5min, 15min, etc.
            $table->integer('size')->nullable(); // unit size (1-5)
            $table->integer('count')->nullable(); // number of units
            $table->json('activity')->nullable(); // array of activities
            $table->json('equipment')->nullable(); // array of equipment
            $table->string('risk')->nullable(); // low, medium, high (for mines)
            $table->string('hazardType')->nullable(); // debris, unexploded, etc.
            $table->integer('avalancheLevel')->nullable(); // 1-5
            $table->string('roadCondition')->nullable(); // good, moderate, bad
            $table->string('weatherCondition')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // additional data
            $table->string('status')->default('active'); // active, resolved, archived
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['map_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['category', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
