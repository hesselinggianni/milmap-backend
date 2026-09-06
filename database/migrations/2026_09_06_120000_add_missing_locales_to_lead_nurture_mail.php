<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bugfix: de dag-4-follow-up van de lead-nurture-drip ("lead_why_milmap")
 * bestond alleen in nl/en, met de campagne op default_locale=nl. Een lead
 * met taal de/es/fr kreeg zijn dag-0-mail correct in eigen taal (die loopt
 * buiten deze registry om via LeadFinishAccountMail, die alle 5 talen al
 * kent), maar de dag-4-mail viel via MailTemplateRegistry::resolve() stil
 * terug op de campagne-default — Nederlands. Precies het "nooit stilzwijgend
 * Nederlands"-principe dat de rest van deze flow (LeadController,
 * LeadNurtureService, LeadFinishAccountMail) al hanteert, maar hier gemist.
 *
 * Fix: de/es/fr toevoegen aan de dag-4-template, én de campagne-fallback van
 * 'nl' naar 'en' zetten zodat een taal die ooit toch ontbreekt Engels wordt,
 * niet Nederlands.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('mail_custom_templates')->where('key', 'lead_why_milmap')->first();
        if (! $row) {
            return; // template bestaat niet (migratie 2026_07_20 nog niet gedraaid) — niets te patchen
        }

        $locales = json_decode($row->locales, true) ?: [];
        if (in_array('de', $locales, true)) {
            return; // al gepatcht (idempotent)
        }

        $subjects = json_decode($row->subjects, true) ?: [];
        $blocks   = json_decode($row->blocks, true) ?: [];

        $subjects['de'] = 'Warum tausende Menschen MilMap im Feld nutzen';
        $subjects['es'] = 'Por qué miles de personas usan MilMap sobre el terreno';
        $subjects['fr'] = 'Pourquoi des milliers de personnes utilisent MilMap sur le terrain';

        $blocks['de'] = [
            ['type' => 'eyebrow', 'text' => 'NOCH UNSICHER?', 'color' => '#2b7fff'],
            ['type' => 'heading', 'text' => 'Warum sich MilMap lohnt'],
            ['type' => 'paragraph', 'text' => 'MilMap vereint alles, was du für die Arbeit im Gelände brauchst, in einer App — kein separates Kartenbuch, Kommunikationsmittel oder Planungsdokument mehr.'],
            ['type' => 'list', 'items' => [
                'Taktische Karten mit MGRS-Koordinaten, online und offline',
                'Routenkarten mit Höhenprofil und 3D-Flyover',
                'Missionen mit O-Group-Briefings und einer Live-Teilnehmerkarte',
                'Ende-zu-Ende-verschlüsselter Team-Chat',
                'Wetter, Geländeanalyse und markierte Gebiete auf derselben Karte',
            ]],
            ['type' => 'paragraph', 'text' => 'Keine Kreditkarte nötig, um loszulegen — probier es einfach aus.'],
            ['type' => 'button', 'label' => 'MilMap ausprobieren', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
        ];

        $blocks['es'] = [
            ['type' => 'eyebrow', 'text' => '¿AÚN CON DUDAS?', 'color' => '#2b7fff'],
            ['type' => 'heading', 'text' => 'Por qué vale la pena probar MilMap'],
            ['type' => 'paragraph', 'text' => 'MilMap reúne todo lo que necesitas para el trabajo de campo en una sola app — sin libro de mapas, herramienta de comunicación y documento de planificación por separado.'],
            ['type' => 'list', 'items' => [
                'Mapas tácticos con coordenadas MGRS, online y offline',
                'Mapas de ruta con perfil de elevación y sobrevuelo 3D',
                'Misiones con briefings O-group y un mapa de participantes en vivo',
                'Chat de equipo cifrado de extremo a extremo',
                'Clima, análisis del terreno y zonas marcadas en el mismo mapa',
            ]],
            ['type' => 'paragraph', 'text' => 'No hace falta tarjeta de crédito para empezar — pruébalo sin más.'],
            ['type' => 'button', 'label' => 'Probar MilMap', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
        ];

        $blocks['fr'] = [
            ['type' => 'eyebrow', 'text' => 'ENCORE DES DOUTES ?', 'color' => '#2b7fff'],
            ['type' => 'heading', 'text' => 'Pourquoi MilMap vaut le coup'],
            ['type' => 'paragraph', 'text' => "MilMap réunit tout ce dont vous avez besoin pour le travail sur le terrain en une seule appli — plus besoin d'un carnet de cartes, d'un outil de communication et d'un document de planification séparés."],
            ['type' => 'list', 'items' => [
                'Cartes tactiques avec coordonnées MGRS, en ligne et hors ligne',
                "Cartes d'itinéraire avec profil altimétrique et survol 3D",
                'Missions avec briefings O-group et carte des participants en direct',
                'Chat d\'équipe chiffré de bout en bout',
                'Météo, analyse du terrain et zones marquées sur la même carte',
            ]],
            ['type' => 'paragraph', 'text' => 'Aucune carte bancaire requise pour commencer — essayez, tout simplement.'],
            ['type' => 'button', 'label' => 'Essayer MilMap', 'url' => 'https://app.milmap.nl', 'color' => '#2b7fff', 'align' => 'center'],
        ];

        $locales = array_values(array_unique(array_merge($locales, ['de', 'es', 'fr'])));

        DB::table('mail_custom_templates')->where('key', 'lead_why_milmap')->update([
            'locales'    => json_encode($locales),
            'subjects'   => json_encode($subjects),
            'blocks'     => json_encode($blocks),
            'updated_at' => now(),
        ]);

        // Fallback op Engels i.p.v. Nederlands zodra een taal ooit toch
        // ontbreekt — zelfde principe als LeadController/LeadNurtureService.
        DB::table('mail_campaigns')
            ->where('name', \App\Services\LeadNurtureService::CAMPAIGN_NAME)
            ->update(['default_locale' => 'en']);
    }

    public function down(): void
    {
        $row = DB::table('mail_custom_templates')->where('key', 'lead_why_milmap')->first();
        if ($row) {
            $locales  = array_values(array_diff(json_decode($row->locales, true) ?: [], ['de', 'es', 'fr']));
            $subjects = json_decode($row->subjects, true) ?: [];
            $blocks   = json_decode($row->blocks, true) ?: [];
            unset($subjects['de'], $subjects['es'], $subjects['fr'], $blocks['de'], $blocks['es'], $blocks['fr']);

            DB::table('mail_custom_templates')->where('key', 'lead_why_milmap')->update([
                'locales'    => json_encode($locales),
                'subjects'   => json_encode($subjects),
                'blocks'     => json_encode($blocks),
                'updated_at' => now(),
            ]);
        }

        DB::table('mail_campaigns')
            ->where('name', \App\Services\LeadNurtureService::CAMPAIGN_NAME)
            ->update(['default_locale' => 'nl']);
    }
};
