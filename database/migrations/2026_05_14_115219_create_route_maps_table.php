<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // relatie naar maps tabel
            $table->uuid('map_id');

            $table->string('title')->nullable();
            $table->foreignId('owner_id')
            ->constrained('users')
            ->cascadeOnDelete();
    

            $table->date('date')->nullable();
            $table->time('time')->nullable();

            $table->string('color')->nullable();

            $table->string('equipment')->nullable();

            $table->float('speed')->default(5);

            $table->string('ic')->nullable();
            $table->string('cs')->nullable();

            $table->json('locations')->nullable();

            $table->timestamps();

            $table
                ->foreign('map_id')
                ->references('id')
                ->on('maps')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_maps');
    }
};