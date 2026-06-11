<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taken-systeem voor missies:
 *
 *   mission_tasks
 *     ─ kort actiepunt binnen één missie, met optionele toewijzing aan een
 *       teamlid (assignee_user_id) of een heel team (assigned_team_id).
 *       Volgorde via `order_index`; status enum 'todo'/'doing'/'done'.
 *
 *   mission_task_templates  (eigendom: user, niet missie-specifiek)
 *     ─ herbruikbaar lijstje "standaard taken bij dit type missie". De
 *       eigenaar kan ze beheren in /menu en bij het openen van een missie
 *       eentje selecteren om in één klik alle items als losse taken aan
 *       de missie toe te voegen.
 *
 *   mission_task_template_items
 *     ─ individuele taak-items binnen een template, ook met `order_index`.
 *
 * IDs op UUID's zodat de FE de schema's straight uit een offline cache kan
 * spelen zonder collision-risico — gelijk aan hoe `missions` en `maps` werken.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mission_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('mission_id');
            $t->unsignedBigInteger('assignee_user_id')->nullable();
            $t->uuid('assigned_team_id')->nullable();
            $t->string('title', 255);
            $t->text('description')->nullable();
            // 'todo'/'doing'/'done' — een enum is brittle voor migrations,
            // dus gewoon een korte varchar met server-side check in de
            // controller en/of FormRequest.
            $t->string('status', 16)->default('todo');
            $t->unsignedInteger('order_index')->default(0);
            $t->timestamp('due_at')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();

            $t->foreign('mission_id')->references('id')->on('missions')->cascadeOnDelete();
            $t->foreign('assignee_user_id')->references('id')->on('users')->nullOnDelete();
            $t->foreign('assigned_team_id')->references('id')->on('teams')->nullOnDelete();
            $t->foreign('created_by')->references('id')->on('users');

            $t->index(['mission_id', 'order_index']);
            $t->index(['assignee_user_id', 'status']);
        });

        Schema::create('mission_task_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('owner_id');
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->timestamps();

            $t->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index('owner_id');
        });

        Schema::create('mission_task_template_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('template_id');
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->unsignedInteger('order_index')->default(0);
            $t->timestamps();

            $t->foreign('template_id')->references('id')->on('mission_task_templates')->cascadeOnDelete();
            $t->index(['template_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_task_template_items');
        Schema::dropIfExists('mission_task_templates');
        Schema::dropIfExists('mission_tasks');
    }
};
