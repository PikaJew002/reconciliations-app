<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->name(),
            'institution_name' => $this->faker->company(),
            'account_name' => $this->faker->word(),
            'account_type' => $this->faker->randomElement([Account::CHECKING, Account::SAVINGS, Account::CREDIT_CARD, Account::CASH]),
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'currency' => 'USD',
            'last_four' => $this->faker->numerify('####'),
            'external_id' => $this->faker->uuid(),
            'is_active' => $this->faker->boolean(),
        ];
    }
}
