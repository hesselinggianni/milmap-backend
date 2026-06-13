<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Centrale opslag voor de deploy-todo's (de "todo-omgeving" in de
     * deploy-app). Was eerst alleen een lokale todos.json; nu de bron van
     * waarheid in Laravel zodat zowel de deploy-app als het MilMap-admin
     * paneel dezelfde lijst lezen/schrijven.
     *
     * De primaire sleutel is een string zodat de deploy-app zijn bestaande
     * client-id's (base36) kan blijven gebruiken; admin-gemaakte rijen krijgen
     * een server-gegenereerde id in hetzelfde formaat.
     */
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->string('id')->primary();

            // De titel bevat doorgaans de volledige prompt en kan lang zijn.
            $table->text('title');
            $table->text('description')->nullable();

            // Tegen welk project draait de taak (frontend|backend|site|...).
            $table->string('repo')->nullable();
            // Claude-modus (fix|feature|...).
            $table->string('mode')->nullable();

            // pending | queued | running | done | failed
            $table->string('status')->default('pending');

            // Waar de taak vandaan komt: 'admin' (MilMap-paneel) of 'deploy'
            // (de deploy-app). Puur informatief voor het overzicht.
            $table->string('source')->default('admin');

            // Exit-code van de laatste Claude-run (0 = ok), null zolang nog niet
            // gedraaid.
            $table->integer('last_exit')->nullable();

            // Vervolg-prompts: [{ "text": "...", "at": "ISO-8601" }, ...]
            $table->json('followups')->nullable();

            // Admin die de taak aanmaakte (null voor deploy-app of verwijderd
            // account). Geen harde afhankelijkheid: nullOnDelete.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
