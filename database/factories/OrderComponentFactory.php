<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderComponentFactory extends Factory
{
    protected $model = OrderComponent::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),

            'order_item_id' => OrderItem::factory(),

            'type' => 'product',

            'description' => fake()->words(2, true),

            'amount' => fake()->randomFloat(2, 1, 40),

            'category_id' => Category::factory()->expense(),

            'category_confidence' => 100,

            'is_user_modified' => false,

            'metadata' => [],
        ];
    }

    public function tax()
    {
        return $this->state(fn () => [
            'type' => 'tax',
            'order_item_id' => OrderItem::factory(),
        ]);
    }

    public function delivery()
    {
        return $this->state(fn () => [
            'type' => 'delivery',
            'order_item_id' => null,
        ]);
    }

    public function tip()
    {
        return $this->state(fn () => [
            'type' => 'tip',
            'order_item_id' => null,
        ]);
    }

    public function discount()
    {
        return $this->state(fn () => [
            'type' => 'discount',
            'order_item_id' => null,
        ]);
    }
}
