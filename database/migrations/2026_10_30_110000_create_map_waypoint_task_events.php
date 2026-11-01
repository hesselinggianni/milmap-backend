<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Logboek van het afvinken/heropenen van aankomsttaken: wie, wanneer en
        // vanaf waar. Bewust los van de taak zelf, zodat de historie blijft
        // bestaan als een taak later wordt verwijderd of heropend.
        Schema::create('map_waypoint_task_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('map_id')->index();
            $t->unsignedBigInteger('map_waypoint_id')->index();
            $t->string('task_id', 64);
            $t->string('task_title', 200);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_name', 120)->nullable();
            $t->boolean('done');
            $t->double('lat')->nullable();
            $t->double('lon')->nullable();
            $t->float('accuracy_m')->nullable();
            $t->unsignedInteger('distance_m')->nullable(); // tot het waypoint
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_waypoint_task_events');
    }
};
