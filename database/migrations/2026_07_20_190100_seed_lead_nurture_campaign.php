<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zet de "lead-nurture"-drip klaar voor mensen die de start-funnel op
 * app.milmap.nl beginnen (e-mail ingevuld) maar geen account afmaken:
 *
 *   Mail 1 — direct (LeadNurtureService::enroll, buiten deze migratie om):
 *     "Maak je account af" + unieke 20%-code, 2 dagen geldig. Wordt via een
 *     losse Mailable verstuurd (heeft een per-lead Stripe-code nodig); de
 *     `lead_finish_account`-rij hieronder is puur ter referentie/admin-inzage.
 *
 *   Mail 2 — 4 dagen later, automatisch via de bestaande follow-up-engine
 *     (ProcessMailFollowups): overtuigende "waarom MilMap de moeite waard is"
 *     mail, gewoon een blok-template — geen per-lead geheimen nodig.
 *
 * Idempotent: elke insert checkt eerst op bestaan, zodat een herhaalde
 * `migrate` (of een dubbele deploy) niets dupliceert.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── Categorie: productinfo (marketing, respecteert opt-out) ────────
        $categoryId = DB::table('mail_categories')->where('key', 'productinfo')->value('id');

        // ── Template 1: "Account afmaken" (referentie — echte verzending via
        //    LeadFinishAccountMail/LeadNurtureService, niet via deze registry). ──
        $finishKey = 'lead_finish_account';
        if (! DB::table('mail_custom_templates')->where('key', $finishKey)->exists()) {
            DB::table('mail_custom_templates')->insert([
                'key'         => $finishKey,
                'label'       => 'Lead: account afmaken (+20% korting)',
                'description' => 'Referentie-kopie — deze mail wordt in werkelijkheid verstuurd via LeadFinishAccountMail (unieke Stripe-code per lead); bewerken hier verandert de echte mail niet.',
                'locales'     => json_encode(['nl', 'en']),
                'subjects'    => json_encode([
                    'nl' => 'Maak je MilMap-account af — 20% korting, 2 dagen geldig',
                    'en' => 'Finish creating your MilMap account — 20% off, 2 days left',
                ]),
                'blocks'      => json_encode([
                    'nl' => [
                        ['type' => 'eyebrow', 'text' => 'JE BENT ER BIJNA', 'color' => '#2b7fff'],
                        ['type' => 'heading', 'text' => 'Maak je MilMap-account af'],
                        ['type' => 'paragraph', 'text' => "Je was bezig met aanmelden bij MilMap — plan routes, deel kaarten en werk samen met je team, zelfs offline in het veld.\n\nJe persoonlijke code geeft 20% korting op je eerste factuur, nog 2 dagen geldig."],
                        ['type' => 'button', 'label' => 'Account afmaken', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
                    ],
                    'en' => [
                        ['type' => 'eyebrow', 'text' => "YOU'RE ALMOST THERE", 'color' => '#2b7fff'],
                        ['type' => 'heading', 'text' => 'Finish creating your MilMap account'],
                        ['type' => 'paragraph', 'text' => "You started signing up for MilMap — plan routes, share maps and collaborate with your team, even offline in the field.\n\nYour personal code gives 20% off your first invoice, valid for 2 more days."],
                        ['type' => 'button', 'label' => 'Finish my account', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
                    ],
                ]),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // ── Template 2: "Waarom MilMap" (echt — via de follow-up-engine) ────
        $whyKey = 'lead_why_milmap';
        if (! DB::table('mail_custom_templates')->where('key', $whyKey)->exists()) {
            DB::table('mail_custom_templates')->insert([
                'key'         => $whyKey,
                'label'       => 'Lead: waarom MilMap de moeite waard is',
                'description' => 'Dag-4-vervolgmail op de lead-nurture-drip — overtuigende uitleg zonder kortingsdruk.',
                'locales'     => json_encode(['nl', 'en']),
                'subjects'    => json_encode([
                    'nl' => 'Waarom duizenden mensen MilMap gebruiken in het veld',
                    'en' => 'Why people rely on MilMap out in the field',
                ]),
                'blocks'      => json_encode([
                    'nl' => [
                        ['type' => 'eyebrow', 'text' => 'NOG STEEDS TWIJFELS?', 'color' => '#2b7fff'],
                        ['type' => 'heading', 'text' => 'Waarom MilMap de moeite waard is'],
                        ['type' => 'paragraph', 'text' => 'MilMap combineert alles wat je nodig hebt voor terreinwerk in één app — geen los kaartenboek, los communicatiemiddel en los planningsdocument meer.'],
                        ['type' => 'list', 'items' => [
                            'Tactische kaarten met MGRS-coördinaten, online én offline',
                            'Routekaarten met hoogteprofiel en 3D-flyover',
                            'Missies met O-group-briefings en een live deelnemerskaart',
                            'End-to-end versleutelde teamchat',
                            'Weer, terreinanalyse en gemarkeerde gebieden op dezelfde kaart',
                        ]],
                        ['type' => 'paragraph', 'text' => 'Geen creditcard nodig om te beginnen — probeer het gewoon.'],
                        ['type' => 'button', 'label' => 'Probeer MilMap', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
                    ],
                    'en' => [
                        ['type' => 'eyebrow', 'text' => 'STILL ON THE FENCE?', 'color' => '#2b7fff'],
                        ['type' => 'heading', 'text' => 'Why MilMap is worth trying'],
                        ['type' => 'paragraph', 'text' => 'MilMap brings everything you need for fieldwork into one app — no more separate map books, comms tools and planning documents.'],
                        ['type' => 'list', 'items' => [
                            'Tactical maps with MGRS coordinates, online and offline',
                            'Route maps with elevation profile and 3D flyover',
                            'Missions with O-group briefings and a live participant map',
                            'End-to-end encrypted team chat',
                            'Weather, terrain analysis and marked areas on the same map',
                        ]],
                        ['type' => 'paragraph', 'text' => 'No credit card needed to get started — just give it a try.'],
                        ['type' => 'button', 'label' => 'Try MilMap', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
                    ],
                ]),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // ── Campagne ─────────────────────────────────────────────────────
        $campaignId = DB::table('mail_campaigns')->where('name', \App\Services\LeadNurtureService::CAMPAIGN_NAME)->value('id');
        if (! $campaignId) {
            $campaignId = DB::table('mail_campaigns')->insertGetId([
                'name'                   => \App\Services\LeadNurtureService::CAMPAIGN_NAME,
                'description'            => 'Drip voor leads uit de start-funnel (app.milmap.nl) die geen account afmaken: retry-mail met 20%-code (2 dagen), gevolgd door een overtuigende mail na 4 dagen.',
                'category_id'            => $categoryId,
                'template_key'           => $finishKey,
                'status'                 => 'active',
                'auto_enroll_on_signup'  => false, // triggert op lead-creatie (LeadController), niet op user-signup
                'default_locale'         => 'nl',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
        }

        // ── Follow-up: dag 4, hangt direct aan de dag-0-mail (parent = null) ─
        $hasFollowup = DB::table('mail_followups')
            ->where('campaign_id', $campaignId)
            ->where('template_key', $whyKey)
            ->exists();
        if (! $hasFollowup) {
            DB::table('mail_followups')->insert([
                'campaign_id'        => $campaignId,
                'parent_followup_id' => null,
                'template_key'       => $whyKey,
                'category_id'        => $categoryId,
                'delay_days'         => 4,
                'condition'          => 'received',
                'position'           => 1,
                'subject_override'   => null,
                'active'             => true,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    public function down(): void
    {
        $campaignId = DB::table('mail_campaigns')->where('name', \App\Services\LeadNurtureService::CAMPAIGN_NAME)->value('id');
        if ($campaignId) {
            DB::table('mail_followups')->where('campaign_id', $campaignId)->delete();
            DB::table('mail_campaign_recipients')->where('campaign_id', $campaignId)->delete();
            DB::table('mail_sends')->where('campaign_id', $campaignId)->delete();
            DB::table('mail_campaigns')->where('id', $campaignId)->delete();
        }
        DB::table('mail_custom_templates')->whereIn('key', ['lead_finish_account', 'lead_why_milmap'])->delete();
    }
};
