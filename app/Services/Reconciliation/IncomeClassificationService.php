<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\ReimbursementGroupTransaction;
use App\Models\TransactionClassificationRule;
use App\Models\TransactionTransferLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IncomeClassificationService
{
    /**
     * @var list<string>
     */
    protected array $incomePatterns = [
        'payroll',
        'direct dep',
        'direct deposit',
        'salary',
        'paycheck',
        'interest payment',
        'interest earned',
        'dividend',
        'deposit from employer',
        'ach credit',
        'dir dep',
    ];

    /**
     * @return array{learned: int, suggested: int}
     */
    public function classifyForUser(int $userId): array
    {
        $learned = 0;
        $suggested = 0;

        $transactions = $this->eligibleCredits($userId);

        if ($transactions->isEmpty()) {
            return ['learned' => 0, 'suggested' => 0];
        }

        $confirmedPatterns = $this->activePatterns($userId, TransactionClassificationRule::ORIGIN_USER_CONFIRMED);
        $rejectedPatterns = $this->activePatterns($userId, TransactionClassificationRule::ORIGIN_USER_REJECTED);

        foreach ($transactions as $transaction) {
            $normalized = $this->normalizedDescription($transaction);

            if ($normalized === '' || isset($rejectedPatterns[$normalized])) {
                continue;
            }

            if (isset($confirmedPatterns[$normalized])) {
                $this->applyLearnedIncome($transaction);
                $learned++;

                continue;
            }

            if ($this->matchesIncomeHeuristic($normalized)) {
                $this->applySuggestedIncome($transaction);
                $suggested++;
            }
        }

        return [
            'learned' => $learned,
            'suggested' => $suggested,
        ];
    }

    public function confirmIncome(BankTransaction $transaction): void
    {
        if ((float) $transaction->amount <= 0) {
            throw new \InvalidArgumentException('Only credits can be classified as income.');
        }

        $normalized = $this->normalizedDescription($transaction);

        $transaction->update([
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_MANUAL,
            'classification_confidence' => 100,
            'status' => 'ignored',
        ]);

        if ($normalized !== '') {
            $this->upsertRule(
                $transaction->user_id,
                $normalized,
                TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            );

            TransactionClassificationRule::query()
                ->where('user_id', $transaction->user_id)
                ->where('normalized_pattern', $normalized)
                ->where('classification', TransactionClassificationRule::CLASSIFICATION_INCOME)
                ->where('origin', TransactionClassificationRule::ORIGIN_USER_REJECTED)
                ->update(['is_active' => false]);
        }
    }

    public function rejectIncome(BankTransaction $transaction): void
    {
        $normalized = $this->normalizedDescription($transaction);

        $transaction->update([
            'classification' => null,
            'classification_source' => null,
            'classification_confidence' => null,
            'status' => 'unmatched',
        ]);

        if ($normalized !== '') {
            $this->upsertRule(
                $transaction->user_id,
                $normalized,
                TransactionClassificationRule::ORIGIN_USER_REJECTED,
            );

            TransactionClassificationRule::query()
                ->where('user_id', $transaction->user_id)
                ->where('normalized_pattern', $normalized)
                ->where('classification', TransactionClassificationRule::CLASSIFICATION_INCOME)
                ->where('origin', TransactionClassificationRule::ORIGIN_USER_CONFIRMED)
                ->update(['is_active' => false]);
        }
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    protected function eligibleCredits(int $userId): Collection
    {
        $linkedIds = TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                TransactionTransferLink::STATUS_SUGGESTED,
                TransactionTransferLink::STATUS_CONFIRMED,
            ])
            ->get(['debit_transaction_id', 'credit_transaction_id'])
            ->flatMap(fn (TransactionTransferLink $link): array => [
                $link->debit_transaction_id,
                $link->credit_transaction_id,
            ])
            ->unique()
            ->all();

        $reimbursementIds = ReimbursementGroupTransaction::query()
            ->whereHas('group', fn ($query) => $query->where('user_id', $userId))
            ->pluck('bank_transaction_id')
            ->all();

        $excluded = array_values(array_unique([...$linkedIds, ...$reimbursementIds]));

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'unmatched')
            ->whereNull('classification')
            ->where('amount', '>', 0)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', $excluded))
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();
    }

    protected function applyLearnedIncome(BankTransaction $transaction): void
    {
        $transaction->update([
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_LEARNED,
            'classification_confidence' => 100,
            'status' => 'ignored',
        ]);
    }

    protected function applySuggestedIncome(BankTransaction $transaction): void
    {
        $transaction->update([
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
            'classification_confidence' => 70,
            // Keep unmatched until the user confirms (confirm-before-hiding).
            'status' => 'unmatched',
        ]);
    }

    /**
     * @return array<string, true>
     */
    protected function activePatterns(int $userId, string $origin): array
    {
        return TransactionClassificationRule::query()
            ->where('user_id', $userId)
            ->where('classification', TransactionClassificationRule::CLASSIFICATION_INCOME)
            ->where('origin', $origin)
            ->where('is_active', true)
            ->pluck('normalized_pattern')
            ->mapWithKeys(fn (string $pattern): array => [$pattern => true])
            ->all();
    }

    protected function upsertRule(int $userId, string $normalizedPattern, string $origin): void
    {
        TransactionClassificationRule::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'normalized_pattern' => $normalizedPattern,
                'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
                'origin' => $origin,
            ],
            [
                'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
                'is_active' => true,
                'metadata' => [],
            ],
        );
    }

    protected function matchesIncomeHeuristic(string $normalized): bool
    {
        foreach ($this->incomePatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizedDescription(BankTransaction $transaction): string
    {
        return $transaction->normalized_description
            ?? Str::of($transaction->description)->lower()->squish()->toString();
    }
}
