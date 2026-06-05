<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-inbox mail configuration for the admin mail client.
 *
 * Each row is one mailbox an admin can log into over IMAP (to read) and SMTP
 * (to reply). Credentials are stored encrypted (Laravel `encrypted` cast on the
 * model) and are never serialised back to the client. Each inbox also carries
 * its own HTML signature/footer so outgoing mail can be branded per inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('name');                 // label, e.g. "Info MilMap"
            $table->string('from_name')->nullable(); // display name on outgoing mail
            $table->string('email');                 // the mailbox address

            // IMAP (reading)
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl'); // ssl|tls|starttls|none
            $table->string('imap_username')->nullable();
            $table->text('imap_password');           // encrypted
            $table->boolean('imap_validate_cert')->default(true);

            // SMTP (sending)
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_encryption')->default('tls');  // ssl|tls|starttls|none
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password');           // encrypted

            // Branding
            $table->longText('signature_html')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
