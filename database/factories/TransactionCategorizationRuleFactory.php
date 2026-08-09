<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionCategorizationRule>
 */
class TransactionCategorizationRuleFactory extends Factory
{
    protected $model = TransactionCategorizationRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory()->bill(),
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => fake()->slug(),
            'amount' => fake()->randomFloat(2, 10, 200),
            'is_active' => true,
        ];
    }
}
