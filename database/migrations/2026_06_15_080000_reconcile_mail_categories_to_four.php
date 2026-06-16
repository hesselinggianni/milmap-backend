<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilieer de voorkeur-categorieën naar de 4 vaste types waar een contact per stuk
 * (e-mail én push) op kan staan: statuspage, nieuwe features, tips & tricks, productinfo.
 * Verwijdert oude seed-categorieën die niet meer in de set zitten (bv. 'offers').
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $cats = [
            ['key' => 'statuspage',   'name' => 'Statuspagina & storingen', 'description' => 'Meldingen over storingen en gepland onderhoud.'],
            ['key' => 'new_features', 'name' => 'Nieuwe features',           'description' => 'Aankondigingen van nieuwe functies in MilMap.'],
            ['key' => 'tips',         'name' => 'Tips & tricks',             'description' => 'Praktische tips en uitleg over MilMap.'],
            ['key' => 'productinfo',  'name' => 'Productinfo',               'description' => 'Product- en abonnementsinformatie.'],
        ];

        foreach ($cats as $c) {
            $exists = DB::table('mail_categories')->where('key', $c['key'])->exists();
            if ($exists) {
                DB::table('mail_categories')->where('key', $c['key'])->update([
                    'name'        => $c['name'],
                    'description' => $c['description'],
                    'updated_at'  => $now,
                ]);
            } else {
                DB::table('mail_categories')->insert($c + [
                    'is_transactional' => false,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            }
        }

        // Categorieën buiten de set verwijderen — maar NIET de afmeldingen weggooien.
        // mail_opt_outs.category_id is cascadeOnDelete: zomaar deleten zou elke
        // expliciete afmelding voor bv. 'offers' wissen → die contacten zouden stil
        // weer aangemeld zijn (consent/GDPR-regressie). We hangen daarom alle
        // verwijzingen eerst om naar 'productinfo' (semantisch het dichtst bij
        // 'offers/aanbiedingen') en verwijderen pas daarna de oude categorie.
        $keep    = array_column($cats, 'key');
        $fallback = DB::table('mail_categories')->where('key', 'productinfo')->value('id');
        $doomed  = DB::table('mail_categories')->whereNotIn('key', $keep)->pluck('id');

        if ($fallback && $doomed->isNotEmpty()) {
            foreach (['mail_opt_outs', 'mail_sends', 'mail_campaigns', 'mail_followups'] as $table) {
                DB::table($table)->whereIn('category_id', $doomed)->update(['category_id' => $fallback]);
            }
        }

        DB::table('mail_categories')->whereNotIn('key', $keep)->delete();
    }

    public function down(): void
    {
        // Niet automatisch terugdraaien (data-reconcile); laat de categorieën staan.
    }
};
