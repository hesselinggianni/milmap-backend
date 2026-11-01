<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('public_profile_follows', function (Blueprint $t) {
            $t->id(); $t->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('followed_id')->constrained('users')->cascadeOnDelete(); $t->timestamps();
            $t->unique(['follower_id', 'followed_id']); $t->index('followed_id');
        });
        Schema::create('public_profile_reports', function (Blueprint $t) {
            $t->id(); $t->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('reported_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 40); $t->string('details', 1000)->nullable();
            $t->string('status', 20)->default('open'); $t->timestamps();
            $t->index(['reported_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('public_profile_reports'); Schema::dropIfExists('public_profile_follows'); }
};
