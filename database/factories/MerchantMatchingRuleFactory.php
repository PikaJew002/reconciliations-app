<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantMatchingRule>
 */
class MerchantMatchingRuleFactory extends Factory
{
    protected $model = MerchantMatchingRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'merchant_id' => fn (array $attributes) => Merchant::factory()->create([
                'user_id' => $attributes['user_id'],
            ]),
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => fake()->unique()->slug(),
            'is_active' => true,
        ];
    }
}
