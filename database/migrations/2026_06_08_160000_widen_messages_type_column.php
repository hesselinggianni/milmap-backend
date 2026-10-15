<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `messages.type` column was originally a strict ENUM
     * ('text','location','mission','image','file'). New structured cards
     * (medevac 9-liner, voice, poll, event, contact) were added to the
     * MessageController validation but NOT to the ENUM, so inserting one of
     * those types passed validation yet failed at the DB layer ("Data
     * truncated for column 'type'") — which is why sending a MEDEVAC 9-liner
     * silently failed.
     *
     * Convert the column to a VARCHAR so the controller's validate() rule is
     * the single source of truth for allowed types. No data migration needed:
     * every existing ENUM value is already a valid string.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type', 32)->default('text')->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['text', 'location', 'mission', 'image', 'file'])->default('text')->change();
        });
    }
};
