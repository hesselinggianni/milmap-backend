<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meerdere toegewezen personen per taak.
 *
 * De legacy-kolom mission_tasks.assignee_user_id blijft bestaan (gevuld met de
 * eerste assignee) zodat oudere lezers/PDF-export blijven werken; deze pivot is
 * de bron van waarheid voor "1 of meerdere leden". Een taak die aan een HEEL
 * team is toegewezen gebruikt assigned_team_id en laat deze pivot leeg.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mission_task_user', function (Blueprint $t) {
            $t->uuid('mission_task_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();

            $t->primary(['mission_task_id', 'user_id']);
            $t->foreign('mission_task_id')->references('id')->on('mission_tasks')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_task_user');
    }
};
