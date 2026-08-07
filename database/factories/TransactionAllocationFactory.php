<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionAllocationFactory extends Factory
{
    protected $model = TransactionAllocation::class;

    public function definition(): array
    {
        return [
            'bank_transaction_id' => BankTransaction::factory(),

            'order_component_id' => OrderComponent::factory(),

            'allocated_amount' => fake()->randomFloat(2, 1, 100),

            'allocation_type' => fake()->randomElement([
                'automatic',
                'manual',
                'imported',
            ]),

            'match_confidence' => fake()->randomFloat(2, 75, 100),

            'notes' => null,

            'metadata' => [],
        ];
    }
}
