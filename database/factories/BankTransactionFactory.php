<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankTransactionFactory extends Factory
{
    protected $model = BankTransaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),

            'import_batch_id' => ImportBatch::factory(),

            'account_id' => Account::factory(),

            'merchant_id' => null,

            'external_id' => fake()->uuid(),

            'posted_at' => fake()->date(),

            'transaction_date' => fake()->date(),

            'description' => fake()->sentence(3),

            'normalized_description' => null,

            'amount' => fake()->randomFloat(2, -300, 300),

            'currency' => 'USD',

            'status' => 'unmatched',

            'notes' => null,

            'metadata' => [],
        ];
    }
}
