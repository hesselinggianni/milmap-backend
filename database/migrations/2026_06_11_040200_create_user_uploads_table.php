<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opslag-grootboek per gebruiker.
 *
 * E2EE chat-bijlagen (chat/YYYY/MM/<uuid>.ext) hebben geen eigenaar-record en de
 * berichten zelf zijn versleuteld — dus de server kan ze niet terug-queryen om
 * opslag toe te rekenen. We loggen daarom bij élke server-side upload een
 * lichte regel (alleen metadata: wie, hoe groot, welk pad/soort) zodat
 * StatsController het verbruik per gebruiker kan optellen. Geen bestandsinhoud,
 * geen ontsleutelbare informatie.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_uploads', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            // 'chat' | 'routemap' | 'mission_logo' | … — vrij label per bron.
            $t->string('kind', 24)->default('chat');
            $t->string('disk', 32)->default('public');
            $t->string('path', 512);
            $t->unsignedBigInteger('size')->default(0);
            $t->string('mime', 191)->nullable();
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_uploads');
    }
};
