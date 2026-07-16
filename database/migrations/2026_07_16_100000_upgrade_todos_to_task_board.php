<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade de deploy-todo's naar een volwaardige taken-omgeving (ClickUp-stijl):
 * extra taak-velden + beheerde labels (categorie & platform) via pivot.
 *
 * Statussen gaan van pending|queued|running|done|failed naar de 8 workflow-
 * fases backlog|voorbereiding|wachtrij|bezig|review|feedback|beta|productie.
 * Bestaande rijen worden gemigreerd (queued→wachtrij, running→bezig,
 * done→review, failed→feedback, rest→backlog) zodat de queue-runner blijft
 * werken (die vertaalt legacy↔nieuw in de deploy-endpoints).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('priority', 20)->default('normaal')->after('status');   // laag|normaal|hoog|urgent
            $table->string('assignee', 60)->nullable()->after('priority');          // 'claude' | user-id | null
            $table->string('app_version', 12)->nullable()->after('assignee');       // "1.2"
            $table->unsignedTinyInteger('deadline_week')->nullable()->after('app_version'); // 1..53
            $table->unsignedSmallInteger('deadline_year')->nullable()->after('deadline_week');
            $table->integer('position')->default(0)->after('deadline_year');        // volgorde binnen een kolom
        });

        // Beheerde labels: één tabel voor zowel 'category' als 'platform'
        // (platform = ook een soort categorie). Kleur voor de UI-chip.
        Schema::create('task_labels', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);   // category | platform
            $table->string('name', 80);
            $table->string('color', 20)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        // Many-to-many: taak ↔ label (meerdere categorieën/platforms per taak).
        Schema::create('todo_task_label', function (Blueprint $table) {
            $table->string('todo_id');
            $table->foreignId('task_label_id')->constrained('task_labels')->cascadeOnDelete();
            $table->primary(['todo_id', 'task_label_id']);
            $table->foreign('todo_id')->references('id')->on('todos')->cascadeOnDelete();
        });

        // Bestaande statussen migreren naar de nieuwe workflow-fases.
        $map = [
            'pending' => 'backlog',
            'queued'  => 'wachtrij',
            'running' => 'bezig',
            'done'    => 'review',
            'failed'  => 'feedback',
        ];
        foreach ($map as $old => $new) {
            DB::table('todos')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_task_label');
        Schema::dropIfExists('task_labels');
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['priority', 'assignee', 'app_version', 'deadline_week', 'deadline_year', 'position']);
        });
        $map = [
            'backlog'      => 'pending',
            'voorbereiding'=> 'pending',
            'wachtrij'     => 'queued',
            'bezig'        => 'running',
            'review'       => 'done',
            'feedback'     => 'failed',
            'beta'         => 'done',
            'productie'    => 'done',
        ];
        foreach ($map as $new => $old) {
            DB::table('todos')->where('status', $new)->update(['status' => $old]);
        }
    }
};
