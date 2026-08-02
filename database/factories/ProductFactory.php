<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'user_id' => User::factory(),

            'expense_category_id' => ExpenseCategory::factory(),

            'name' => ucwords($name),

            'normalized_name' => Str::lower($name),

            'brand' => fake()->optional()->company(),

            'upc' => fake()->optional()->ean13(),

            'size' => fake()->optional()->randomElement([
                '8 oz',
                '16 oz',
                '32 oz',
                '1 lb',
                '1 gal',
            ]),

            'unit' => fake()->optional()->randomElement([
                'each',
                'pack',
                'box',
                'bottle',
                'bag',
            ]),

            'is_taxable' => fake()->boolean(70),

            'category_confidence' => 100,

            'is_user_modified' => false,

            'metadata' => [],
        ];
    }
}
