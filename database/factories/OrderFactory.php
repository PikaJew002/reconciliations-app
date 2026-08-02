<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 300);
        $tax = fake()->randomFloat(2, 0, 15);
        $delivery = fake()->randomFloat(2, 0, 10);
        $tip = fake()->randomFloat(2, 0, 8);
        $discount = fake()->randomFloat(2, 0, 20);

        return [
            'user_id' => User::factory(),

            'import_batch_id' => ImportBatch::factory(),

            'merchant_id' => Merchant::factory(),

            'order_number' => strtoupper(fake()->bothify('ORD######')),

            'ordered_at' => now(),

            'fulfilled_at' => now(),

            'delivered_at' => now(),

            'subtotal' => $subtotal,

            'tax' => $tax,

            'delivery_fee' => $delivery,

            'tip' => $tip,

            'discount' => $discount,

            'total' => $subtotal + $tax + $delivery + $tip - $discount,

            'currency' => 'USD',

            'shipping_state' => 'KY',

            'shipping_zip' => '40509',

            'status' => 'imported',

            'metadata' => [],
        ];
    }
}
