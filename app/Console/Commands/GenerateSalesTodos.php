<?php

namespace App\Console\Commands;

use App\Services\SalesTodoService;
use Illuminate\Console\Command;

class GenerateSalesTodos extends Command
{
    protected $signature = 'sales:generate-todos';

    protected $description = 'Scan de sales-signalen en maak/actualiseer de bijbehorende taken in de takenlijst.';

    public function handle(SalesTodoService $sales): int
    {
        $result = $sales->generate();

        $this->info("Sales-taken: {$result['created']} aangemaakt, {$result['updated']} bijgewerkt.");

        return self::SUCCESS;
    }
}
