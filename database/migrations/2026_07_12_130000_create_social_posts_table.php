<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Één keer opgestelde sociale post die naar meerdere platformen kan worden
     * gepubliceerd. `statuses` bewaart per platform de uitkomst
     * ({state: pending|manual|published|failed, url, error, published_at}).
     */
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('link')->nullable();
            $table->string('image_path')->nullable();
            $table->json('platforms')->nullable();   // ['pinterest','instagram','dribbble']
            $table->json('statuses')->nullable();     // per-platform resultaat
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
