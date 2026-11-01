<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->boolean('all_teams')->default(false);
        });
        Schema::create('mission_task_team_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('mission_task_id')->constrained('mission_tasks')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('status')->default('todo');
            $table->json('comments')->nullable();
            $table->timestamps();
            $table->unique(['mission_task_id', 'team_id']);
        });
        DB::table('mission_tasks')->whereNotNull('assigned_team_id')->orderBy('id')->chunk(200, function ($tasks) {
            foreach ($tasks as $task) {
                DB::table('mission_task_team_progress')->insert([
                    'mission_task_id' => $task->id, 'team_id' => $task->assigned_team_id,
                    'status' => $task->status, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_task_team_progress');
        Schema::table('mission_tasks', fn (Blueprint $table) => $table->dropColumn('all_teams'));
    }
};
