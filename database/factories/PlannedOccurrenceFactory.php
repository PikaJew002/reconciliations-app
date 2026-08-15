<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedOccurrence>
 */
class PlannedOccurrenceFactory extends Factory
{
    protected $model = PlannedOccurrence::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'template_id' => PlannedTemplate::factory(),
            'category_id' => Category::factory()->income(),
            'merchant_id' => null,
            'bank_transaction_id' => null,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'amount' => null,
            'expected_date' => '2026-03-01',
            'expected_amount' => 3000.00,
            'lookback_days' => 7,
            'lookforward_days' => 3,
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ];
    }

    public function forTemplate(PlannedTemplate $template, string $expectedDate): static
    {
        return $this->state(fn () => [
            'user_id' => $template->user_id,
            'template_id' => $template->id,
            'category_id' => $template->category_id,
            'merchant_id' => $template->merchant_id,
            'classification' => $template->classification,
            'match_mode' => $template->match_mode,
            'normalized_pattern' => $template->normalized_pattern,
            'amount' => $template->amount,
            'expected_date' => $expectedDate,
            'expected_amount' => $template->expected_amount,
            'lookback_days' => $template->lookback_days,
            'lookforward_days' => $template->lookforward_days,
        ]);
    }

    public function resolved(?BankTransaction $transaction = null): static
    {
        return $this->state(function () use ($transaction) {
            $attributes = [
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ];

            if ($transaction !== null) {
                $attributes['bank_transaction_id'] = $transaction->id;
                $attributes['user_id'] = $transaction->user_id;
            }

            return $attributes;
        });
    }
}
