<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Vervangt de hardgecodeerde PRODUCTS-array in ClothingOrderController door
 * een echte catalogus: categorieën (T-shirt, Hoodie, ...) met daaronder
 * kleurvarianten (producten). Admin kan hierdoor producten toevoegen/wijzigen
 * zonder een deploy. Seedt de bestaande 8 producten zodat de shop na migreren
 * precies hetzelfde blijft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clothing_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 60)->unique();
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('clothing_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->constrained('clothing_categories')->cascadeOnDelete();
            $table->string('key', 60)->unique();
            $table->string('name', 150);
            $table->string('color', 60)->nullable();
            $table->string('swatch', 9)->default('#1a1a1a');
            $table->decimal('price', 8, 2);
            $table->json('sizes');
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('clothing_products');
        Schema::dropIfExists('clothing_categories');
    }

    private function seed(): void
    {
        $now = now();
        $categories = [
            ['key' => 'tshirt',       'label' => 'T-shirt',       'sort_order' => 1],
            ['key' => 'hemd',         'label' => 'Hemd',          'sort_order' => 2],
            ['key' => 'hoodie',       'label' => 'Hoodie',        'sort_order' => 3],
            ['key' => 'sweatshirt',   'label' => 'Sweatshirt',    'sort_order' => 4],
            ['key' => 'trainingspak', 'label' => 'Trainingspak',  'sort_order' => 5],
        ];
        foreach ($categories as &$c) {
            $c['created_at'] = $now;
            $c['updated_at'] = $now;
        }
        unset($c);
        DB::table('clothing_categories')->insert($categories);

        $categoryIds = DB::table('clothing_categories')->pluck('id', 'key');
        $sizesFull  = json_encode(['S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL']);
        $sizesShort = json_encode(['S', 'M', 'L', 'XL', 'XXL']);

        $products = [
            ['key' => 'tshirt_desert',   'name' => 'T-shirt desert',               'category' => 'tshirt',       'color' => 'Desert',         'swatch' => '#c8a96a', 'price' => 20,  'sizes' => $sizesFull],
            ['key' => 'tshirt_milgr',    'name' => 'T-shirt mil. gr.',              'category' => 'tshirt',       'color' => 'Militair groen', 'swatch' => '#4b5320', 'price' => 20,  'sizes' => $sizesFull],
            ['key' => 'tshirt_milgr_h',  'name' => 'T-shirt militair groen licht',  'category' => 'tshirt',       'color' => 'Militair groen', 'swatch' => '#4b5320', 'price' => 20,  'sizes' => $sizesFull],
            ['key' => 'tshirt_sport',    'name' => 'T-shirt sport',                 'category' => 'tshirt',       'color' => 'Zwart',          'swatch' => '#1a1a1a', 'price' => 20,  'sizes' => $sizesFull],
            ['key' => 'hemd_sport',      'name' => 'Hemd sport',                    'category' => 'hemd',         'color' => 'Zwart',          'swatch' => '#1a1a1a', 'price' => 20,  'sizes' => $sizesFull],
            ['key' => 'hoodie',          'name' => 'Hoodie',                        'category' => 'hoodie',       'color' => 'Zwart',          'swatch' => '#1a1a1a', 'price' => 35,  'sizes' => $sizesFull],
            ['key' => 'sweatshirt',      'name' => 'Sweatshirt',                    'category' => 'sweatshirt',   'color' => 'Zwart',          'swatch' => '#1a1a1a', 'price' => 35,  'sizes' => $sizesFull],
            ['key' => 'trainingspak',    'name' => 'Trainingspak',                  'category' => 'trainingspak', 'color' => 'Zwart',          'swatch' => '#1a1a1a', 'price' => 110, 'sizes' => $sizesShort],
        ];

        $rows = [];
        foreach ($products as $i => $p) {
            $rows[] = [
                'category_id' => $categoryIds[$p['category']],
                'key'         => $p['key'],
                'name'        => $p['name'],
                'color'       => $p['color'],
                'swatch'      => $p['swatch'],
                'price'       => $p['price'],
                'sizes'       => $p['sizes'],
                'active'      => true,
                'sort_order'  => $i,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }
        DB::table('clothing_products')->insert($rows);
    }
};
