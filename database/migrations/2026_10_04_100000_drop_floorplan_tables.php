<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('floorplan_elements');
        Schema::dropIfExists('waypoint_floorplans');
    }

    public function down(): void
    {
        // Plattegrond-functie is verwijderd; niet herstelbaar.
    }
};
