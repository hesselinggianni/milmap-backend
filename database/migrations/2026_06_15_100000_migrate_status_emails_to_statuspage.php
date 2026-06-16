<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Koppel de oude opt-in `settings->notify_status_emails` aan de e-mailvoorkeur van de
 * 'statuspage'-categorie. Statusmail was opt-in (default uit); de categorie werkt opt-out
 * (default aan). Om de bestaande staat te behouden zetten we voor elke gebruiker die NIET
 * had aangevinkt een statuspage-opt-out (blijft dus uit); wie had aangevinkt blijft aan.
 */
return new class extends Migration {
    public function up(): void
    {
        $statuspageId = DB::table('mail_categories')->where('key', 'statuspage')->value('id');
        if (! $statuspageId) {
            return;
        }
        $now = now();

        DB::table('users')->select('id', 'email', 'settings')->orderBy('id')->chunk(500, function ($users) use ($statuspageId, $now) {
            foreach ($users as $u) {
                if (! $u->email) {
                    continue;
                }
                $settings = json_decode($u->settings ?? '{}', true) ?: [];
                $optedIn = ($settings['notify_status_emails'] ?? false) === true;
                if ($optedIn) {
                    continue; // wil statusmail → geen opt-out
                }
                $email = mb_strtolower(trim($u->email));
                $already = DB::table('mail_opt_outs')->where('email', $email)->where('category_id', $statuspageId)->exists();
                if (! $already) {
                    DB::table('mail_opt_outs')->insert([
                        'email'           => $email,
                        'category_id'     => $statuspageId,
                        'unsubscribed_at' => $now,
                        'source'          => 'account',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Niet terugdraaien (data-migratie).
    }
};
