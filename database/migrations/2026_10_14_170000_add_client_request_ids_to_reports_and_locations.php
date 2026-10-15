<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['reports', 'locations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('client_request_id', 100)->nullable();
                $table->unique(['user_id', 'client_request_id'], $name.'_client_request_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['reports', 'locations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique($name.'_client_request_unique');
                $table->dropColumn('client_request_id');
            });
        }
    }
};
