<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\User;
use App\Models\VenmoActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

class VenmoActivityFactory extends Factory
{
    protected $model = VenmoActivity::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'import_batch_id' => ImportBatch::factory(),
            'external_id' => (string) fake()->unique()->numerify('###################'),
            'occurred_at' => fake()->dateTimeBetween('-2 months'),
            'type' => VenmoActivity::TYPE_PAYMENT,
            'status' => 'Complete',
            'note' => fake()->words(2, true),
            'from_name' => fake()->name(),
            'to_name' => fake()->name(),
            'amount' => fake()->randomFloat(2, -300, 300),
            'fee' => null,
            'funding_source' => null,
            'destination' => null,
            'funding_last_four' => null,
            'destination_last_four' => null,
            'bank_transaction_id' => null,
            'cashed_out_by_activity_id' => null,
            'match_status' => VenmoActivity::STATUS_UNMATCHED,
            'metadata' => [],
        ];
    }

    public function cardPayment(string $lastFour = '2195', float $amount = -250.00): static
    {
        return $this->state(fn (): array => [
            'type' => VenmoActivity::TYPE_PAYMENT,
            'amount' => $amount,
            'funding_source' => "Mastercard *{$lastFour}",
            'funding_last_four' => $lastFour,
            'destination' => null,
            'destination_last_four' => null,
        ]);
    }

    public function bankFundedMerchant(string $lastFour = '6218', float $amount = -15.30): static
    {
        return $this->state(fn (): array => [
            'type' => VenmoActivity::TYPE_MERCHANT_TRANSACTION,
            'amount' => $amount,
            'funding_source' => "CUMBERLAND VALLEY NATIONAL BANK Personal Checking *{$lastFour}",
            'funding_last_four' => $lastFour,
            'destination' => null,
            'destination_last_four' => null,
        ]);
    }

    public function incomingPayment(float $amount = 10.00): static
    {
        return $this->state(fn (): array => [
            'type' => VenmoActivity::TYPE_PAYMENT,
            'amount' => $amount,
            'funding_source' => 'Venmo balance',
            'funding_last_four' => null,
            'destination' => null,
            'destination_last_four' => null,
        ]);
    }

    public function standardTransfer(string $lastFour = '6218', float $amount = -10.00): static
    {
        return $this->state(fn (): array => [
            'type' => 'standard_transfer',
            'amount' => $amount,
            'from_name' => null,
            'to_name' => null,
            'note' => null,
            'funding_source' => null,
            'funding_last_four' => null,
            'destination' => "Cumberland Valley National Bank & Trust Company *{$lastFour}",
            'destination_last_four' => $lastFour,
        ]);
    }
}
