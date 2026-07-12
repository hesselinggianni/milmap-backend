<?php

namespace App\Http\Controllers;

use App\Services\SalesTodoService;

class SalesTodoController extends Controller
{
    public function __construct(private SalesTodoService $sales)
    {
    }

    /**
     * Toon de actieve sales-signalen zonder taken aan te maken (preview).
     */
    public function preview()
    {
        return response()->json([
            'signals' => $this->sales->signals(),
        ], 200);
    }

    /**
     * Genereer/actualiseer sales-taken uit de actieve signalen.
     */
    public function generate()
    {
        $result = $this->sales->generate();

        return response()->json([
            'message' => "{$result['created']} nieuwe taak/taken aangemaakt, {$result['updated']} bijgewerkt.",
            'created' => $result['created'],
            'updated' => $result['updated'],
            'tasks'   => $result['tasks'],
        ], 200);
    }
}
