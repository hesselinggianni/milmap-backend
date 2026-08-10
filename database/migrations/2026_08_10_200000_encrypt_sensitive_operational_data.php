<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Versleutelt de operationeel gevoeligste velden at rest.
 *
 * WAAROM DEZE VELDEN
 *  - nine_lines / nine_line_revisions: MEDEVAC-aanvragen met letsel- en
 *    slachtoffergegevens. Dat is gezondheidsdata → bijzondere categorie
 *    (AVG art. 9) en hoort niet leesbaar in een dump te staan.
 *  - mission_radio_channels: frequentie, roepnaam en cryptofill = COMSEC.
 *  - mission_briefings / mission_risks: het feitelijke bevel.
 *
 * WAAROM EERST DE KOLOMTYPES
 * Een versleutelde waarde is fors langer dan het origineel (een frequentie van
 * 12 tekens wordt >200). De bestaande kolommen zijn te krap (varchar(32/64))
 * en — belangrijker — een aantal is `json`. MySQL VALIDEERT die kolommen: een
 * versleutelde string is geen geldige JSON en wordt botweg geweigerd. Zonder
 * deze stap faalt de migratie of kapt hij data af.
 *
 * COÖRDINATEN BLIJVEN BEWUST LEESBAAR. Waypoints/locaties worden met
 * bounding-box-queries op geïndexeerde lat/lon opgehaald; die versleutelen
 * breekt de kaart. Dat hoort thuis bij encryptie op schijf-/DB-niveau.
 */
return new class extends Migration
{
    /** Kolommen die versleuteld worden: tabel => [kolom => nieuw type]. */
    private const KOLOMMEN = [
        'nine_lines'             => ['lines' => 'LONGTEXT'],
        // Let op: de revisietabel heet de kolom `changes`, niet `lines` — maar
        // hij bevat dezelfde MEDEVAC-inhoud (de oude versie van de negen
        // regels). Hem overslaan zou de historie leesbaar laten staan.
        'nine_line_revisions'    => ['changes' => 'LONGTEXT'],
        'mission_radio_channels' => [
            'frequency'  => 'TEXT',
            'callsign'   => 'TEXT',
            'encryption' => 'TEXT',
        ],
        'mission_risks'          => ['description' => 'TEXT', 'mitigation' => 'TEXT'],
        'mission_briefings'      => [
            'timeline'         => 'LONGTEXT',
            'pace_plan'        => 'LONGTEXT',
            'weather'          => 'LONGTEXT',
            'light_conditions' => 'LONGTEXT',
            'enemy_forces'            => 'TEXT',
            'friendly_forces'         => 'TEXT',
            'civilian_considerations' => 'TEXT',
            'ground_conditions'       => 'TEXT',
            'commander_intent'        => 'TEXT',
            'action_on_procedures'    => 'TEXT',
            'casevac'                 => 'TEXT',
            'medevac'                 => 'TEXT',
        ],
    ];

    public function up(): void
    {
        // 1) Kolommen verbreden / json → text.
        foreach (self::KOLOMMEN as $tabel => $kolommen) {
            if (!Schema::hasTable($tabel)) continue;
            foreach ($kolommen as $kolom => $type) {
                if (!Schema::hasColumn($tabel, $kolom)) continue;
                // NULL toestaan blijft zoals het was; alleen het type groeit.
                DB::statement("ALTER TABLE `{$tabel}` MODIFY `{$kolom}` {$type} NULL");
            }
        }

        // 2) Bestaande rijen versleutelen. Zonder deze stap blijven oude
        //    records leesbaar en zou de cast ze bij het uitlezen bovendien
        //    proberen te ontsleutelen (en falen).
        foreach (self::KOLOMMEN as $tabel => $kolommen) {
            if (!Schema::hasTable($tabel)) continue;
            $velden = array_keys($kolommen);

            DB::table($tabel)->orderBy('id')->chunkById(200, function ($rijen) use ($tabel, $velden) {
                foreach ($rijen as $rij) {
                    $wijziging = [];
                    foreach ($velden as $veld) {
                        if (!property_exists($rij, $veld)) continue;
                        $waarde = $rij->$veld;
                        if ($waarde === null || $waarde === '') continue;
                        if ($this->isVersleuteld($waarde)) continue; // idempotent
                        $wijziging[$veld] = Crypt::encryptString($waarde);
                    }
                    if ($wijziging) {
                        DB::table($tabel)->where('id', $rij->id)->update($wijziging);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Eerst ontsleutelen, anders staat er onleesbare brij in kolommen die
        // daarna weer als json gevalideerd worden.
        foreach (self::KOLOMMEN as $tabel => $kolommen) {
            if (!Schema::hasTable($tabel)) continue;
            $velden = array_keys($kolommen);

            DB::table($tabel)->orderBy('id')->chunkById(200, function ($rijen) use ($tabel, $velden) {
                foreach ($rijen as $rij) {
                    $wijziging = [];
                    foreach ($velden as $veld) {
                        if (!property_exists($rij, $veld)) continue;
                        $waarde = $rij->$veld;
                        if ($waarde === null || $waarde === '') continue;
                        try {
                            $wijziging[$veld] = Crypt::decryptString($waarde);
                        } catch (DecryptException) {
                            // Stond al leesbaar — laten staan.
                        }
                    }
                    if ($wijziging) {
                        DB::table($tabel)->where('id', $rij->id)->update($wijziging);
                    }
                }
            });
        }

        // Kolomtypes bewust NIET terugdraaien naar json/varchar: dat zou bij
        // afwijkende data alsnog stukgaan, en een ruimer type schaadt niet.
    }

    /** Al versleuteld? Dan overslaan — houdt de migratie herhaalbaar. */
    private function isVersleuteld(string $waarde): bool
    {
        try {
            Crypt::decryptString($waarde);
            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
