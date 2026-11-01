<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_route_comments', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 10); // route | track
            $t->string('subject_id', 64);
            $t->unsignedBigInteger('user_id');
            $t->string('body', 500);
            $t->json('mentions')->nullable(); // user-id's van getagde profielen
            $t->timestamps();
            $t->softDeletes();
            $t->index(['subject_type', 'subject_id', 'created_at']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_route_comments');
    }
};
