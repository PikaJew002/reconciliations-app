<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedTemplate>
 */
class PlannedTemplateFactory extends Factory
{
    protected $model = PlannedTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory()->income(),
            'merchant_id' => null,
            'name' => 'Paycheck',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'amount' => null,
            'expected_day' => 1,
            'expected_amount' => 3000.00,
            'lookback_days' => 7,
            'lookforward_days' => 3,
            'is_active' => true,
        ];
    }

    public function bill(): static
    {
        return $this->state(fn () => [
            'category_id' => Category::factory()->bill(),
            'name' => 'Electric',
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'duke energy',
            'amount' => 140.00,
            'expected_amount' => 140.00,
        ]);
    }
}
