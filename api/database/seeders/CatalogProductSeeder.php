<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use Illuminate\Database\Seeder;

class CatalogProductSeeder extends Seeder
{
    /**
     * Seed a small canonical catalog, including a variant pair for matching demos.
     */
    public function run(): void
    {
        $products = [
            ['name' => 'Cordless Drill Driver', 'brand' => 'Makita', 'model_number' => 'DF333D', 'barcode' => '0088381673114',
                'category' => 'Power tools', 'sku_aliases' => ['DF333DWYE'], 'attributes' => ['voltage' => '12V', 'color' => 'teal']],
            ['name' => 'Claw Hammer 16oz', 'brand' => 'Stanley', 'model_number' => null, 'barcode' => '3253561510130',
                'category' => 'Hand tools', 'attributes' => ['weight' => '16oz']],
            ['name' => 'Common Wire Nails 2 inch', 'category' => 'Fasteners', 'attributes' => ['length' => '2in', 'pack_quantity' => 500]],
            ['name' => 'Common Wire Nails 3 inch', 'category' => 'Fasteners', 'attributes' => ['length' => '3in', 'pack_quantity' => 500]],
        ];

        foreach ($products as $product) {
            CatalogProduct::query()->updateOrCreate(
                ['name' => $product['name'], 'brand' => $product['brand'] ?? null],
                $product,
            );
        }
    }
}
