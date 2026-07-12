<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Map;
use App\Models\MapShare;
use App\Models\Mission;
use App\Models\RouteMap;
use App\Models\User;
use Illuminate\Database\Seeder;

/*
 * Publieke demo-dataset "Oefenlocatie Oirschot".
 *
 * Zet één samenhangende showcase klaar onder het demo-account (demo@milmap.nl):
 *   - een kaart met 11 illustratieve punten (POI's) over de Oirschotse Heide,
 *   - een patrouilleroute met 7 waypoints (met correcte MGRS-coördinaten),
 *   - een volledige demo-missie met 5-paragrafen O-group (SMEAC),
 *   - een publieke deel-link met een vaste, leesbare token.
 *
 * Alle punten zijn bewust FICTIEF/illustratief — puur als feature-demo, geen
 * operationele info. Idempotent: draai 'm opnieuw en de oude demo wordt
 * vervangen. Runnen:  php artisan db:seed --class=OirschotDemoSeeder
 */
class OirschotDemoSeeder extends Seeder
{
    /** Vaste token → stabiele publieke URL /share/oirschot-demo. */
    private const SHARE_TOKEN = 'oirschot-demo';
    private const MAP_TITLE   = 'Oefenlocatie Oirschot — Demo';
    private const MISSION_NAME = 'Patrouille-oefening Oirschot';

    public function run(): void
    {
        $demo = User::where('email', 'demo@milmap.nl')->first();
        if (! $demo) {
            $this->command?->error('Oirschot-demo: demo@milmap.nl niet gevonden — seeder overgeslagen.');
            return;
        }

        // ── Opruimen van een vorige demo (idempotent) ──────────────────────
        $oldMaps = Map::withTrashed()
            ->where('owner_id', $demo->id)
            ->where('title', self::MAP_TITLE)
            ->get();
        foreach ($oldMaps as $m) {
            Location::where('map_id', $m->id)->delete();
            RouteMap::where('map_id', $m->id)->forceDelete();
            MapShare::where('map_id', $m->id)->delete();
            $m->forceDelete();
        }
        Mission::withTrashed()
            ->where('owner_id', $demo->id)
            ->where('name', self::MISSION_NAME)
            ->get()
            ->each(fn ($mission) => $mission->forceDelete());
        MapShare::where('token', self::SHARE_TOKEN)->delete();

        // ── De kaart ───────────────────────────────────────────────────────
        $map = Map::create([
            'title'    => self::MAP_TITLE,
            'owner_id' => $demo->id,
            'status'   => 'active',
            'settings' => [
                'locationformat'  => 'mgrs',
                'mgrs_precision'  => 5,
                'language'        => 'nl',
                'selectedTheme'   => 'outdoors-v12',
            ],
        ]);

        // ── Punten (POI's) — elk gekoppeld aan een MilMap-feature ──────────
        foreach ($this->points() as $p) {
            Location::create([
                'map_id'      => $map->id,
                'user_id'     => $demo->id,
                'name'        => $p['name'],
                'description' => $p['description'],
                'latitude'    => $p['lat'],
                'longitude'   => $p['lon'],
                'color'       => $p['color'],
                'icon'        => $p['icon'],
            ]);
        }

        // ── De missie (moet er zijn vóór de route, i.v.m. mission_id) ──────
        $mission = Mission::create([
            'owner_id'       => $demo->id,
            'name'           => self::MISSION_NAME,
            'mission_number' => 'OEF-OIRSCHOT-01',
            'classification' => 'OEFENING · ONGERUBRICEERD',
            'description'    => 'Demonstratie-missie over de Oirschotse Heide. Laat het volledige '
                . 'missiesysteem zien: O-group (SMEAC), route, waypoints en punten. '
                . 'Alle locaties zijn illustratief.',
            'status'         => 'active',
            'date'           => now()->toDateString(),
            'time'           => '06:00',
            'ogroup'         => $this->ogroup(),
            'map'            => [
                'id'     => $map->id,
                'source' => 'server',
                'title'  => $map->title,
            ],
        ]);

        // ── De patrouilleroute (op de kaart én in de missie) ───────────────
        RouteMap::create([
            'map_id'     => $map->id,
            'mission_id' => $mission->id,
            'owner_id'   => $demo->id,
            'title'      => 'Patrouilleroute Oirschot',
            'date'       => now()->toDateString(),
            'time'       => '06:00',
            'color'      => '#f59e0b',
            'speed'      => 4,          // km/u te voet
            'callsign'   => 'ADDER-1',
            'eenheid'    => 'Demo-peloton',
            'sterkte'    => 8,
            'kaartblad'  => '51 Oost (Oirschot)',
            'locations'  => $this->routeWaypoints(),
        ]);

        // ── Publieke deel-link ─────────────────────────────────────────────
        MapShare::create([
            'map_id'     => $map->id,
            'created_by' => $demo->id,
            'token'      => self::SHARE_TOKEN,
            'title'      => 'Oefenlocatie Oirschot — MilMap-demo',
            'settings'   => [
                'showTable'   => true,
                'showMarkers' => true,
                'showLines'   => true,
            ],
        ]);

        $this->command?->info('Oirschot-demo klaar:');
        $this->command?->info('  • kaart:   ' . $map->id);
        $this->command?->info('  • punten:  ' . count($this->points()));
        $this->command?->info('  • route:   7 waypoints');
        $this->command?->info('  • missie:  ' . $mission->id);
        $this->command?->info('  • share:   /share/' . self::SHARE_TOKEN);
    }

    /**
     * 11 illustratieve punten. Elke beschrijving legt en passant een
     * MilMap-feature uit, zodat de demo tegelijk een mini-handleiding is.
     */
    private function points(): array
    {
        return [
            ['name' => 'Verzamelpunt kazerne', 'lat' => 51.4783, 'lon' => 5.3365, 'color' => '#22c55e', 'icon' => 'star',
             'description' => 'Startpunt bij de Gen.-maj. de Ruyter van Steveninckkazerne. Zet hier je verzamelpunt en deel de kaart met je peloton via een deel-link — iedereen ziet dezelfde punten en route.'],
            ['name' => 'Terreiningang (poort)', 'lat' => 51.4830, 'lon' => 5.3230, 'color' => '#64748b', 'icon' => 'square',
             'description' => 'Toegangspoort tot het oefenterrein (Oirschotsedijk). Markeer in- en uitgangen zodat iedereen dezelfde route het terrein op en af loopt.'],
            ['name' => 'OP Noord (observatiepost)', 'lat' => 51.4945, 'lon' => 5.2895, 'color' => '#f59e0b', 'icon' => 'triangle',
             'description' => 'Observatiepost aan de noordrand van de heide. Voeg een punt toe met beschrijving en gebruik de MGRS-coördinaat rechtstreeks in je rapportage.'],
            ['name' => 'Oefendorp (MOUT/FIBUA)', 'lat' => 51.4805, 'lon' => 5.2880, 'color' => '#ef4444', 'icon' => 'flag',
             'description' => 'Oefendorp voor optreden in bebouwd gebied. Wissel tussen satelliet- en topografische laag om binnen- en aanlooproutes te plannen.'],
            ['name' => 'Schietbaan-sector', 'lat' => 51.4710, 'lon' => 5.2905, 'color' => '#dc2626', 'icon' => 'square',
             'description' => 'Schietbaan-sector (illustratief). Baken zones af als no-go en deel ze mee op de gedeelde kaart.'],
            ['name' => 'HLZ Alpha (heli-landingszone)', 'lat' => 51.4855, 'lon' => 5.2770, 'color' => '#06b6d4', 'icon' => 'triangle',
             'description' => 'Helikopter-landingszone op de open heide. In een missie koppel je hier een 9-liner / CASEVAC-melding aan.'],
            ['name' => 'Rally Point RV1', 'lat' => 51.4835, 'lon' => 5.2930, 'color' => '#a855f7', 'icon' => 'star',
             'description' => 'Rally point (RV) bij contact. Deel live locaties zodat teams elkaar hier weer terugvinden.'],
            ['name' => 'Medevac-RV (CASEVAC)', 'lat' => 51.4775, 'lon' => 5.2830, 'color' => '#e11d48', 'icon' => 'flag',
             'description' => 'Gewonden-RV. Hangt in de missie aan de 9-liner en aan de medische paragraaf van de O-group.'],
            ['name' => 'Bevoorradingspunt (water/mun)', 'lat' => 51.4890, 'lon' => 5.3020, 'color' => '#3b82f6', 'icon' => 'square',
             'description' => 'Bevoorradingspunt. Log het als punt en verwijs ernaar in de logistieke paragraaf (aanvulling & verzorging).'],
            ['name' => 'Uitkijktoren (referentiepunt)', 'lat' => 51.4910, 'lon' => 5.2960, 'color' => '#f59e0b', 'icon' => 'triangle',
             'description' => 'Herkenbaar referentiepunt op de heide. Handig als ankerpunt bij het briefen van je route.'],
            ['name' => 'Hindernisbaan', 'lat' => 51.4820, 'lon' => 5.3120, 'color' => '#14b8a6', 'icon' => 'dot',
             'description' => 'Hindernis-/stormbaan. Voeg trainingslocaties toe als punten met een korte omschrijving.'],
        ];
    }

    /**
     * 7 route-waypoints (patrouille-lus). MGRS is vooraf berekend met de
     * mgrs-library op 5-cijferige precisie (zone 31U).
     */
    private function routeWaypoints(): array
    {
        $wps = [
            ['title' => 'Start / verzamelpunt', 'lat' => 51.4783, 'lon' => 5.3365, 'mgrs' => '31UFT6224905604', 'cp' => 'Start'],
            ['title' => 'Hindernisbaan',         'lat' => 51.4820, 'lon' => 5.3120, 'mgrs' => '31UFT6053505962', 'cp' => null],
            ['title' => 'Bevoorradingspunt',     'lat' => 51.4890, 'lon' => 5.3020, 'mgrs' => '31UFT5981606718', 'cp' => 'Water/bevoorrading'],
            ['title' => 'OP Noord',              'lat' => 51.4945, 'lon' => 5.2895, 'mgrs' => '31UFT5893007302', 'cp' => 'OP Noord'],
            ['title' => 'Rally Point RV1',       'lat' => 51.4835, 'lon' => 5.2930, 'mgrs' => '31UFT5921106087', 'cp' => 'RV1'],
            ['title' => 'Oefendorp (MOUT)',      'lat' => 51.4805, 'lon' => 5.2880, 'mgrs' => '31UFT5887405743', 'cp' => 'Oefendorp'],
            ['title' => 'Medevac-RV',            'lat' => 51.4775, 'lon' => 5.2830, 'mgrs' => '31UFT5853705398', 'cp' => 'Medevac-RV'],
        ];

        $out = [];
        foreach ($wps as $i => $w) {
            $out[] = [
                'id'         => 1800000000000 + $i + 1,
                'no'         => $i + 1,
                'lon'        => $w['lon'],
                'lat'        => $w['lat'],
                'mgrs'       => $w['mgrs'],
                'elevation'  => 20,
                'title'      => $w['title'],
                'checkpoint' => [
                    'enabled' => $w['cp'] !== null,
                    'label'   => $w['cp'],
                ],
                'pause'      => ['type' => null, 'custom' => 0],
                'generated'  => false,
                'images'     => [],
                'flyoverImage' => null,
            ];
        }
        return $out;
    }

    /**
     * Volledige 5-paragrafen O-group (SMEAC), Dutch, illustratief scenario.
     */
    private function ogroup(): array
    {
        return [
            'warning_order' => 'Patrouille-oefening Oirschotse Heide. Verplaatsing te voet, daglicht. '
                . 'Uitrusting: gevechtstenue, dagrantsoen, water 3 L, kaart & kompas.',
            'situation' => [
                'general'     => 'Oefenterrein Oirschotse Heide. Gemengd bos, heide en open zandvlaktes; enkele zandpaden. '
                    . 'Weer: droog, wind ZW 3 Bft, zicht goed. Zonsopkomst 06:12, ondergang 21:40.',
                'enemy'       => 'Kleine vijandelijke verkennersgroep (2–3 man) vermoed rond het oefendorp (MOUT). '
                    . 'Mogelijk observatie vanaf hoge gebouwen.',
                'friendly'    => 'Hogere: cie-oogmerk is het terrein tot fase-lijn ROOD onder controle brengen. '
                    . 'Naburig: 2e peloton links (west), 3e peloton in reserve op de kazerne.',
                'attachments' => 'Toegevoegd: 1 medic (bij groep 2). Onttrokken: geen.',
                'civil'       => 'Geen burgers op het terrein tijdens de oefening. Openbare weg vormt de oostgrens — niet betreden.',
            ],
            'mission' => [
                'statement' => 'Demo-peloton (ADDER) patrouilleert vanaf 06:00 de noordelijke Oirschotse Heide via OP Noord '
                    . 'naar het oefendorp, teneinde vijandelijke verkenners te lokaliseren en te melden.',
                'purpose'   => 'Teneinde de cie een actueel beeld van de vijandelijke aanwezigheid te geven vóór fase 2.',
            ],
            'execution' => [
                'intent'       => 'Onopgemerkt naderen, waarnemen vanaf OP Noord, en het oefendorp verkennen zonder beslissend gevecht.',
                'concept'      => 'In drie fasen: verplaatsing naar OP Noord, waarneming, verkenning oefendorp. '
                    . 'Bewegen in bosranden, open vlaktes bonzend nemen.',
                'main_effort'  => 'Waarneming vanaf OP Noord — daar hangt het situatiebeeld van af.',
                'phases'       => "Fase 1: verzamelpunt → OP Noord (via uitkijktoren).\n"
                    . "Fase 2: waarneming vanaf OP Noord (30 min).\n"
                    . "Fase 3: verkenning oefendorp, terug via RV1 en bevoorradingspunt.",
                'tasks'        => "Groep 1: point / waarneming OP Noord.\n"
                    . "Groep 2 (met medic): flankbeveiliging + verkenning oefendorp.\n"
                    . "Pelotonscmdt: bij groep 1, verbinding met cie.",
                'coordinating' => 'Vertrek 06:00. Fase-lijn ROOD = zuidrand oefendorp — niet overschrijden. '
                    . 'Bij contact: terugvallen op RV1. Herkenning: oranje paneel omhoog.',
            ],
            'logistics' => [
                'supply'    => 'Water 3 L p.p., dagrantsoen. Herbevoorrading bij het bevoorradingspunt (water/munitie).',
                'medical'   => 'Medic bij groep 2. Gewonden-RV = Medevac-RV. CASEVAC via 9-liner naar HLZ Alpha.',
                'transport' => 'Verplaatsing volledig te voet. Terugkeer per voertuig vanaf de kazerne na afloop.',
                'equipment' => 'Kaart & kompas verplicht; MilMap op de telefoon als back-up. 1 verrekijker per groep.',
                'personnel' => 'Sterkte 8. Geen aflossing gepland; oefenduur ± 4 uur.',
            ],
            'command_signals' => [
                'command'     => 'Pelotonscmdt bij groep 1. Bij uitval neemt cmdt groep 2 het bevel over.',
                'signal'      => 'Kanaal 1 (peloton), kanaal 2 (naar cie). Roepnaam peloton: ADDER, cmdt: ADDER-6.',
                'pace'        => 'P: radio kanaal 1 · A: radio kanaal 2 · C: mobiel/SMS · E: handsignalen / oranje paneel.',
                'recognition' => 'Wachtwoord: "HEIDE" — antwoord: "ZAND". Herkenningsteken overdag: oranje paneel.',
                'emergency'   => 'Lost-comms: terug naar laatste RV, 15 min wachten, dan naar verzamelpunt kazerne.',
            ],
            'rv'           => 'RV1 (Rally Point) bij contact; verzamelpunt kazerne als eind-RV.',
            'participants' => [],
        ];
    }
}
