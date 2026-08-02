<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $quantity = fake()->randomElement([1, 1, 1, 2, 3]);
        $unitPrice = fake()->randomFloat(2, 1, 40);

        return [
            'order_id' => Order::factory(),

            'product_id' => Product::factory(),

            'line_number' => fake()->unique()->numberBetween(1, 100),

            'sku' => fake()->optional()->bothify('SKU-#####'),

            'upc' => fake()->optional()->ean13(),

            'description' => fake()->words(3, true),

            'normalized_description' => null,

            'quantity' => $quantity,

            'unit_price' => $unitPrice,

            'extended_price' => round($quantity * $unitPrice, 2),

            'taxable' => fake()->boolean(70),

            'match_confidence' => 100,

            'metadata' => [],
        ];
    }
}
