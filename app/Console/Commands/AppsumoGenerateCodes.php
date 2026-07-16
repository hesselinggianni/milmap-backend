<?php

namespace App\Console\Commands;

use App\Models\AppsumoCode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Genereer AppSumo lifetime-deal codes en zet ze in de appsumo_codes-tabel.
 * Optioneel meteen een CSV exporteren (het formaat dat AppSumo verwacht:
 * één kolom, geen header, geen dubbele codes).
 *
 * Voorbeelden:
 *   php artisan appsumo:generate 10000
 *   php artisan appsumo:generate 10000 --csv=storage/app/appsumo-codes.csv --csv-count=5000
 */
class AppsumoGenerateCodes extends Command
{
    protected $signature = 'appsumo:generate
        {count=10000 : Aantal nieuwe codes om te genereren}
        {--csv= : Pad om een CSV te schrijven (één kolom, geen header)}
        {--csv-count= : Aantal codes in de CSV (default: alle nieuw gegenereerde)}';

    protected $description = 'Genereer unieke AppSumo-codes in de database en exporteer optioneel een CSV.';

    /** Onambigue tekens (geen 0/O, 1/I) zodat codes goed leesbaar/overtypbaar zijn. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function handle(): int
    {
        $count = max(1, (int) $this->argument('count'));

        $this->info("Genereren van {$count} unieke AppSumo-codes...");

        $generated = [];   // code => true, dedupe binnen deze run
        $created    = 0;
        $bar = $this->output->createProgressBar($count);

        while (count($generated) < $count) {
            $batch = [];
            while (count($batch) < min(2000, $count - count($generated))) {
                $code = $this->makeCode();
                if (isset($generated[$code])) {
                    continue;
                }
                $generated[$code] = true;
                $batch[$code] = true;
            }

            // Filter codes die al in de DB staan (extreem onwaarschijnlijk, maar veilig).
            $existing = AppsumoCode::whereIn('code', array_keys($batch))->pluck('code')->all();
            foreach ($existing as $dupe) {
                unset($batch[$dupe]);
            }

            $now = now();
            $rows = array_map(fn ($code) => [
                'code'       => $code,
                'status'     => AppsumoCode::STATUS_AVAILABLE,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_keys($batch));

            if ($rows) {
                foreach (array_chunk($rows, 1000) as $chunk) {
                    AppsumoCode::insert($chunk);
                }
                $created += count($rows);
                $bar->advance(count($rows));
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("{$created} codes aangemaakt.");

        if ($csvPath = $this->option('csv')) {
            $csvCount = (int) ($this->option('csv-count') ?: $created);
            $this->exportCsv($csvPath, array_slice(array_keys($generated), 0, $csvCount));
        }

        return self::SUCCESS;
    }

    private function makeCode(): string
    {
        $segment = fn (int $len) => collect(range(1, $len))
            ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
            ->implode('');

        return 'MILMAP-' . $segment(5) . '-' . $segment(5) . '-' . $segment(5);
    }

    private function exportCsv(string $path, array $codes): void
    {
        // Randomiseer de volgorde (AppSumo-eis) en schrijf één kolom, geen header.
        shuffle($codes);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, implode("\r\n", $codes) . "\r\n");
        $this->info(count($codes) . " codes geëxporteerd naar {$path}");
    }
}
