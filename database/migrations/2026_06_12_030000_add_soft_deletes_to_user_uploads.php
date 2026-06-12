<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-deletes op `user_uploads` zodat het opslag-beheerscherm media eerst
 * naar de prullenbak kan sturen — uit het zicht, maar nog 60 dagen te
 * herstellen. `php artisan trash:purge` ruimt ze daarna definitief op (de
 * controller daarvoor wordt uitgebreid).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('user_uploads') && ! Schema::hasColumn('user_uploads', 'deleted_at')) {
            Schema::table('user_uploads', function (Blueprint $t) {
                $t->softDeletes()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_uploads') && Schema::hasColumn('user_uploads', 'deleted_at')) {
            Schema::table('user_uploads', function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
