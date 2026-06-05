<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Polymorphic, e-mail-keyed invitations.
     *
     * Unlike map_collaborators / mission_collaborators (which require an
     * existing user_id), this table can hold an invitation for someone who
     * does NOT yet have a MilMap account. It is the single source of truth
     * for the Hub inbox and powers both "invite an existing user by e-mail"
     * and "invite a brand-new user who registers a free view-only account".
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The invitee (always stored, lower-cased).
            $table->string('email', 191)->index();

            // Set when the e-mail matches an existing user at invite time
            // (lets the Hub inbox surface it to that logged-in user).
            $table->unsignedBigInteger('invited_user_id')->nullable();

            // Polymorphic target: App\Models\Mission | App\Models\Map (both uuid PKs).
            $table->string('invitable_type', 191);
            $table->uuid('invitable_id');

            // Role the inviter requested. Note: a brand-new (invited) account is
            // forced to 'viewer' on acceptance regardless of this value.
            $table->enum('role', ['viewer', 'editor', 'admin'])->default('viewer');

            $table->unsignedBigInteger('invited_by');

            $table->string('token', 64)->unique();

            $table->enum('status', ['pending', 'accepted', 'declined', 'revoked'])
                ->default('pending');

            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['invitable_type', 'invitable_id']);
            $table->index(['email', 'status']);
            $table->index('token');

            // One open invite per e-mail per resource.
            $table->unique(['email', 'invitable_type', 'invitable_id'], 'uniq_invite_email_resource');

            $table->foreign('invited_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('invited_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('accepted_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
