<?php

namespace App\Console\Commands;

use App\Support\GeoIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

/**
 * Haalt de DB-IP "IP to Country Lite"-database op (CC BY 4.0, maandelijks
 * nieuw) en zet hem op de plek waar App\Support\GeoIp hem leest. Daarmee
 * verlaat het IP-adres van een bezoeker de server niet meer voor de
 * valutakeuze en de analytics-landcode.
 *
 * Probeert de huidige maand en valt terug op de vorige (de nieuwe staat er
 * pas na de eerste dagen van de maand). De bestaande database wordt alleen
 * vervangen als de download een leesbare mmdb is.
 */
class GeoIpUpdate extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download de DB-IP Country Lite-database voor lokale GeoIP';

    public function handle(): int
    {
        $target = GeoIp::databasePath();
        @mkdir(dirname($target), 0755, true);

        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = 'https://download.db-ip.com/free/dbip-country-lite-' . $month->format('Y-m') . '.mmdb.gz';
            try {
                $resp = Http::timeout(120)->get($url);
            } catch (\Throwable $e) {
                $this->warn("Download mislukt: {$url} ({$e->getMessage()})");
                continue;
            }
            if (! $resp->successful()) {
                $this->line("Niet beschikbaar: {$url} (HTTP {$resp->status()})");
                continue;
            }

            $mmdb = @gzdecode($resp->body());
            if ($mmdb === false || strlen($mmdb) < 1024 * 1024) {
                $this->warn("Ongeldig bestand: {$url}");
                continue;
            }

            $tmp = $target . '.tmp';
            file_put_contents($tmp, $mmdb);
            try {
                (new Reader($tmp))->close();
            } catch (\Throwable $e) {
                @unlink($tmp);
                $this->warn("Geen leesbare mmdb: {$url}");
                continue;
            }
            rename($tmp, $target);

            $this->info('GeoIP-database bijgewerkt: ' . basename($url));
            return self::SUCCESS;
        }

        $this->error('Geen GeoIP-database kunnen ophalen; de bestaande blijft staan.');
        return self::FAILURE;
    }
}
