<?php

namespace Database\Factories;

use App\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogProduct>
 */
class CatalogProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'brand' => fake()->company(),
            'model_number' => strtoupper(fake()->bothify('??###?')),
            'barcode' => fake()->unique()->ean13(),
            'category' => 'Power tools',
            'sku_aliases' => [],
            'attributes' => [],
        ];
    }
}
