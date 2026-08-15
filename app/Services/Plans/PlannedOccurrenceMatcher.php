<?php

namespace App\Services\Plans;

use App\Models\BankTransaction;
use App\Models\PlannedOccurrence;
use App\Services\Reconciliation\TransactionMatchEvaluator;
use Carbon\Carbon;

class PlannedOccurrenceMatcher
{
    public function __construct(
        protected TransactionMatchEvaluator $evaluator,
        protected PlannedOccurrenceGenerator $generator,
    ) {}

    /**
     * @return array{matched: int}
     */
    public function matchForUser(int $userId): array
    {
        $this->generator->ensureForUser($userId);

        $occurrences = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('status', PlannedOccurrence::STATUS_PLANNED)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where(function ($query) {
                $query->whereNull('template_id')
                    ->orWhereHas('template', fn ($templateQuery) => $templateQuery->where('is_active', true));
            })
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();

        if ($occurrences->isEmpty()) {
            return ['matched' => 0];
        }

        $claimedTransactionIds = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('amount', '>', 0)
            ->where(function ($query) {
                $query->whereNull('classification')
                    ->orWhere('classification', BankTransaction::CLASSIFICATION_INCOME);
            })
            ->when(
                $claimedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $claimedTransactionIds),
            )
            ->orderBy('id')
            ->get();

        $pairs = [];

        foreach ($occurrences as $occurrence) {
            foreach ($candidates as $transaction) {
                if (! $this->occurrenceMatchesTransaction($occurrence, $transaction)) {
                    continue;
                }

                $pairs[] = [
                    'distance' => $this->dateDistance($occurrence, $transaction),
                    'occurrence_id' => $occurrence->id,
                    'transaction_id' => $transaction->id,
                ];
            }
        }

        usort($pairs, function (array $left, array $right): int {
            return [$left['distance'], $left['occurrence_id'], $left['transaction_id']]
                <=> [$right['distance'], $right['occurrence_id'], $right['transaction_id']];
        });

        $usedOccurrences = [];
        $usedTransactions = [];
        $matched = 0;
        $occurrencesById = $occurrences->keyBy('id');
        $candidatesById = $candidates->keyBy('id');

        foreach ($pairs as $pair) {
            $occurrenceId = $pair['occurrence_id'];
            $transactionId = $pair['transaction_id'];

            if (isset($usedOccurrences[$occurrenceId]) || isset($usedTransactions[$transactionId])) {
                continue;
            }

            $occurrence = $occurrencesById->get($occurrenceId);
            $transaction = $candidatesById->get($transactionId);

            if ($occurrence === null || $transaction === null) {
                continue;
            }

            $this->resolve($occurrence, $transaction);
            $usedOccurrences[$occurrenceId] = true;
            $usedTransactions[$transactionId] = true;
            $matched++;
        }

        return ['matched' => $matched];
    }

    public function link(PlannedOccurrence $occurrence, BankTransaction $transaction): void
    {
        if (! $occurrence->isPlanned()) {
            throw new \InvalidArgumentException('Only planned occurrences can be linked.');
        }

        if ((float) $transaction->amount <= 0) {
            throw new \InvalidArgumentException('Only credits can be linked to an income occurrence.');
        }

        $alreadyLinked = PlannedOccurrence::query()
            ->where('bank_transaction_id', $transaction->id)
            ->where('id', '!=', $occurrence->id)
            ->exists();

        if ($alreadyLinked) {
            throw new \InvalidArgumentException('That transaction is already linked to a plan.');
        }

        $this->resolve($occurrence, $transaction);
    }

    protected function occurrenceMatchesTransaction(
        PlannedOccurrence $occurrence,
        BankTransaction $transaction,
    ): bool {
        $postedAt = Carbon::parse($transaction->posted_at)->startOfDay();

        if ($postedAt->lt($occurrence->windowStart()) || $postedAt->gt($occurrence->windowEnd())) {
            return false;
        }

        return $this->evaluator->matches(
            $transaction,
            $occurrence->match_mode,
            $occurrence->normalized_pattern,
            $occurrence->merchant_id !== null ? (int) $occurrence->merchant_id : null,
            $occurrence->amount,
            $occurrence->classification,
        );
    }

    protected function dateDistance(PlannedOccurrence $occurrence, BankTransaction $transaction): int
    {
        return (int) abs(
            Carbon::parse($occurrence->expected_date)->startOfDay()
                ->diffInDays(Carbon::parse($transaction->posted_at)->startOfDay()),
        );
    }

    protected function resolve(PlannedOccurrence $occurrence, BankTransaction $transaction): void
    {
        $txUpdates = [];

        if ($transaction->classification === null) {
            $txUpdates['classification'] = $occurrence->classification;
            $txUpdates['classification_source'] = BankTransaction::CLASSIFICATION_SOURCE_LEARNED;
            $txUpdates['classification_confidence'] = 100;
        }

        if ($transaction->category_id === null && $occurrence->category_id !== null) {
            $txUpdates['category_id'] = $occurrence->category_id;
        }

        if ($txUpdates !== []) {
            $txUpdates['status'] = 'ignored';
            $transaction->update($txUpdates);
        }

        $occurrence->update([
            'bank_transaction_id' => $transaction->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
        ]);
    }
}
