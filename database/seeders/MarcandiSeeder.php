<?php

namespace Database\Seeders;

use App\Models\MarcandiShop;
use App\Http\Controllers\MarcandiController;
use Illuminate\Database\Seeder;

/*
 * Zet de eerste shop "OC 26-1 Duitsland" klaar met de MARCANDI-catalogus.
 * Idempotent: draait niets als de shop al bestaat.
 */
class MarcandiSeeder extends Seeder
{
    public function run(): void
    {
        if (MarcandiShop::where('slug', 'oc-26-1-duitsland')->exists()) {
            $this->command?->info('Marcandi: shop bestaat al, overgeslagen.');
            return;
        }

        $shop = MarcandiShop::create([
            'slug'      => 'oc-26-1-duitsland',
            'title'     => 'OC 26-1 Duitsland',
            'location'  => 'Duitsland',
            'info'      => 'Duty-free bestellijst MARCANDI Buitenland. Vul per product het gewenste aantal in.',
            'closes_at' => null,
            'active'    => true,
        ]);

        app(MarcandiController::class)->seedProducts($shop);

        $count = $shop->products()->where('kind', 'item')->count();
        $this->command?->info("Marcandi: shop '{$shop->title}' aangemaakt met {$count} producten.");
    }
}
