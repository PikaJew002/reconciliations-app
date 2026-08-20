<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\PendingSpend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingSpend>
 */
class PendingSpendFactory extends Factory
{
    protected $model = PendingSpend::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory()->state([
                'account_type' => Account::CHECKING,
            ]),
            'merchant_id' => Merchant::factory()->state([
                'supports_order_import' => false,
            ]),
            'category_id' => null,
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
            'spent_at' => '2026-08-15 12:00:00',
            'amount' => 12.50,
            'card_last_four' => '1234',
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'status' => PendingSpend::STATUS_PENDING,
            'review_reason' => null,
            'bank_transaction_id' => null,
            'venmo_activity_id' => null,
            'notes' => null,
        ];
    }

    public function venmo(): static
    {
        return $this->state(fn (): array => [
            'source' => PendingSpend::SOURCE_VENMO,
            'merchant_id' => null,
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn (): array => [
            'source' => PendingSpend::SOURCE_CREDIT_CARD,
        ]);
    }

    public function needsReview(string $reason = PendingSpend::REVIEW_NOT_FOUND): static
    {
        return $this->state(fn (): array => [
            'status' => PendingSpend::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
        ]);
    }

    public function resolved(?BankTransaction $transaction = null): static
    {
        return $this->state(function () use ($transaction): array {
            $attributes = [
                'status' => PendingSpend::STATUS_RESOLVED,
                'review_reason' => null,
            ];

            if ($transaction !== null) {
                $attributes['bank_transaction_id'] = $transaction->id;
                $attributes['user_id'] = $transaction->user_id;
                $attributes['account_id'] = $transaction->account_id;
            }

            return $attributes;
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PendingSpend::STATUS_CANCELLED,
            'review_reason' => null,
        ]);
    }
}
