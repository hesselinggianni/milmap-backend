<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // ✅ UUID foreign key (correct)
    $table->uuid('map_id')->nullable();

    $table->foreign('map_id')
        ->references('id')
        ->on('maps')
        ->onDelete('cascade');

            $table->string('title');
            $table->string('subtitle')->nullable();

            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);

            $table->timestamp('created_at')->useCurrent();

         
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_histories');
    }
};