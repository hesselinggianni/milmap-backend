<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAKO app-modus (zie docs/kako-app-modus.md): deelnemers lopen een opdracht in de
 * app, checken in bij een prikker en delen — met toestemming en alleen binnen een
 * tijdvenster — hun positie met de instructeur.
 *
 * Bewust nieuwe tabellen i.p.v. kolommen op route_assignments: de privacygevoelige
 * data heeft een eigen levensduur (bewaartermijn) en eigen FK-gedrag bij het
 * verwijderen van een account.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Koppeling deelnemer-plek ↔ account, toestemming, venster en laatste positie.
        Schema::create('kako_app_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_assignment_id');
            // = participants[].id in de opdracht
            $table->string('participant_key', 64);
            // Account verwijderd → de plek blijft (met naam-momentopname), de koppeling niet.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('display_name', 120);
            // invited | accepted | declined (kiest papier) | revoked (instructeur)
            $table->string('status', 16)->default('invited');
            $table->string('invite_code', 16)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // Toestemming voor positie-delen (check-ins zijn onderdeel van deelnemen zelf).
            $table->unsignedSmallInteger('consent_version')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();

            // Tijdvenster: posities worden alleen binnen dit venster bewaard. Leeg = niet gestart.
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_until')->nullable();

            // Heeft de deelnemer de afgeschermde modus zelf verlaten? (gelogd)
            $table->timestamp('mode_left_at')->nullable();

            // Gedenormaliseerd voor de live-kaart: één rij lezen i.p.v. de sample-tabel.
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lon', 10, 7)->nullable();
            $table->float('last_accuracy')->nullable();
            $table->dateTime('last_position_at', 3)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            $table->unique(['route_assignment_id', 'participant_key'], 'kako_part_key_unique');
            // Eén account = één deelnemer per opdracht (NULL's botsen niet).
            $table->unique(['route_assignment_id', 'user_id'], 'kako_part_user_unique');
            $table->index(['user_id', 'status']);
        });

        // Elke check-in-poging, ook de mislukte.
        Schema::create('kako_app_checkins', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_assignment_id');
            $table->foreignId('participant_id')->constrained('kako_app_participants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Idempotentie: een herhaling uit de offline-outbox maakt geen tweede rij.
            $table->string('client_uuid', 64);
            $table->string('post_id', 64);
            // Momentopname: blijft kloppen als de opdracht later wordt aangepast.
            $table->string('post_label', 64)->nullable();
            // post | start | finish
            $table->string('role', 8)->default('post');
            // manual | qr | nfc
            $table->string('method', 8)->default('manual');
            $table->dateTime('attempted_at', 3);
            $table->dateTime('received_at', 3);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();
            $table->float('accuracy')->nullable();
            $table->float('distance_m')->nullable();
            $table->boolean('within_radius')->nullable();
            // ok | too_far | no_position | wrong_code | not_on_route | method_not_allowed | attempts_exhausted | outside_window
            $table->string('result', 24);
            $table->string('device', 64)->nullable();

            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            $table->unique(['participant_id', 'client_uuid'], 'kako_checkin_uuid_unique');
            $table->index(['route_assignment_id', 'post_id']);
            $table->index(['participant_id', 'attempted_at']);
        });

        // Positie-samples (alleen met toestemming en binnen het venster).
        Schema::create('kako_app_positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_assignment_id');
            $table->foreignId('participant_id')->constrained('kako_app_participants')->cascadeOnDelete();
            // Account weg → posities weg (anders dan bij de deelnemer-plek, die blijft).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // GPS-tijd (position.timestamp), niet de apparaatklok.
            $table->dateTime('recorded_at', 3);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->float('accuracy')->nullable();
            $table->float('speed')->nullable();
            $table->float('heading')->nullable();

            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            $table->unique(['participant_id', 'recorded_at'], 'kako_pos_unique');
            $table->index(['route_assignment_id', 'recorded_at']);
        });

        // Levenscyclus en toegangslog: alleen voor eigenaar/bewerker en de deelnemer zelf.
        Schema::create('kako_app_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_assignment_id');
            $table->foreignId('participant_id')->nullable()->constrained('kako_app_participants')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 190)->nullable();
            $table->string('type', 32);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            $table->index(['route_assignment_id', 'id']);
            $table->index(['participant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kako_app_events');
        Schema::dropIfExists('kako_app_positions');
        Schema::dropIfExists('kako_app_checkins');
        Schema::dropIfExists('kako_app_participants');
    }
};
