<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductAlias;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductAliasFactory extends Factory
{
    protected $model = ProductAlias::class;

    public function definition(): array
    {
        $description = fake()->words(4, true);

        return [
            'product_id' => Product::factory(),

            'merchant_id' => Merchant::factory(),

            'merchant_description' => ucwords($description),

            'normalized_description' => Str::lower($description),

            'sku' => fake()->optional()->bothify('SKU-#####'),

            'upc' => fake()->optional()->ean13(),

            'match_confidence' => 100,

            'is_user_confirmed' => true,

            'metadata' => [],
        ];
    }
}
