<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-follow-ups: een follow-up kan een ouder-follow-up hebben (boom, tot 5 diep).
 * parent_followup_id NULL = directe follow-up op de hoofd-campagnemail. De engine
 * meet de vertraging + conditie t.o.v. de send van de OUDER (of de hoofdmail bij NULL).
 * 'position' blijft de volgorde tussen siblings (voor drag-and-drop).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mail_followups', function (Blueprint $t) {
            // De foreign key maakt zelf al een index op deze kolom aan.
            $t->foreignId('parent_followup_id')->nullable()->after('campaign_id')
                ->constrained('mail_followups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_followups', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_followup_id');
        });
    }
};
