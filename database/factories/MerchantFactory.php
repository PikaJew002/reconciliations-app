<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),

            'name' => $name,

            'normalized_name' => Str::lower($name),

            'website' => 'https://' . Str::slug($name) . '.com',

            'type' => fake()->randomElement([
                'retailer',
                'restaurant',
                'service',
                'utility',
            ]),

            'supports_order_import' => fake()->boolean(),

            'supports_api' => fake()->boolean(),

            'metadata' => [],
        ];
    }
}
