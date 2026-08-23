<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\TransactionAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(
        protected int $dateWindowDays = 7,
        protected int $subsetCandidateCap = 12,
    ) {}

    /**
     * @return int Number of bank transactions matched.
     */
    public function reconcileForUser(int $userId): int
    {
        $matchedTransactionIds = [];

        $matchedTransactionIds = array_merge(
            $matchedTransactionIds,
            $this->reconcileExactOneToOne($userId),
        );

        $matchedTransactionIds = array_merge(
            $matchedTransactionIds,
            $this->reconcileExactMultiTransaction($userId),
        );

        return count(array_unique($matchedTransactionIds));
    }

    /**
     * @return list<int>
     */
    protected function reconcileExactOneToOne(int $userId): array
    {
        $matchedTransactionIds = [];

        foreach ($this->openOrders($userId) as $order) {
            $candidates = $this->candidateTransactions($userId, $order)
                ->filter(fn (BankTransaction $transaction): bool => $this->amountsEqual(
                    abs((float) $transaction->amount),
                    (float) $order->total,
                ))
                ->values();

            if ($candidates->count() !== 1) {
                continue;
            }

            $transaction = $candidates->first();

            if ($this->allocateTransactionsToOrder(collect([$transaction]), $order)) {
                $matchedTransactionIds[] = $transaction->id;
            }
        }

        return $matchedTransactionIds;
    }

    /**
     * @return list<int>
     */
    protected function reconcileExactMultiTransaction(int $userId): array
    {
        $matchedTransactionIds = [];
        $postedAtRange = $this->postedAtRange($userId);

        foreach ($this->openOrders($userId) as $order) {
            if ($this->isNearImportEdge($order, $postedAtRange)) {
                continue;
            }

            $candidates = $this->candidateTransactions($userId, $order)->values();

            if ($candidates->isEmpty() || $candidates->count() > $this->subsetCandidateCap) {
                continue;
            }

            $subset = $this->findUniqueExactSubset($candidates, $this->toCents((float) $order->total));

            if ($subset === null || $subset->count() < 2) {
                continue;
            }

            if ($this->allocateTransactionsToOrder($subset, $order)) {
                foreach ($subset as $transaction) {
                    $matchedTransactionIds[] = $transaction->id;
                }
            }
        }

        return $matchedTransactionIds;
    }

    /**
     * @return Collection<int, Order>
     */
    protected function openOrders(int $userId): Collection
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'reconciled')
            ->with(['components.allocations'])
            ->orderBy('ordered_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Order $order): bool => $order->components->isNotEmpty())
            ->values();
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    protected function candidateTransactions(int $userId, Order $order): Collection
    {
        $orderDate = $this->orderDate($order);

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $order->merchant_id)
            ->availableForExpenseMatching()
            ->where('amount', '<', 0)
            ->whereNotNull('merchant_id')
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get()
            ->filter(function (BankTransaction $transaction) use ($order, $orderDate): bool {
                if (! $this->paymentInstrumentsAlign($order, $transaction)) {
                    return false;
                }

                if ($orderDate === null) {
                    return true;
                }

                return $this->datesAlign($this->postedAtDate($transaction), $orderDate);
            })
            ->values();
    }

    /**
     * @param  Collection<int, BankTransaction>  $transactions
     */
    public function allocateExactTransactions(Collection $transactions, Order $order): bool
    {
        return $this->allocateTransactionsToOrder($transactions, $order);
    }

    /**
     * @param  Collection<int, BankTransaction>  $transactions
     */
    protected function allocateTransactionsToOrder(Collection $transactions, Order $order): bool
    {
        $transactions = $transactions->sortBy('id')->values();
        $orderTotalCents = $this->toCents((float) $order->total);
        $transactionTotalCents = $transactions->sum(
            fn (BankTransaction $transaction): int => $this->toCents(abs((float) $transaction->amount)),
        );

        if ($transactions->isEmpty() || $transactionTotalCents !== $orderTotalCents) {
            return false;
        }

        try {
            DB::transaction(function () use ($transactions, $order): void {
                $order->refresh();
                $order->load(['components.allocations']);

                if ($order->status === 'reconciled' || $this->orderRemainingAmount($order) < 0.01) {
                    throw new \RuntimeException('Order is not allocatable.');
                }

                foreach ($transactions as $transaction) {
                    $transaction->refresh();

                    if ($transaction->status !== 'unmatched' || abs((float) $transaction->remaining_amount) < 0.01) {
                        throw new \RuntimeException('Transaction is not allocatable.');
                    }
                }

                foreach ($transactions as $transaction) {
                    $remaining = abs((float) $transaction->amount);
                    $order->load(['components.allocations']);

                    foreach ($order->components->sortBy('id') as $component) {
                        if ($remaining < 0.01) {
                            break;
                        }

                        $componentRemaining = (float) $component->remaining_amount;

                        if ($componentRemaining < 0.01) {
                            continue;
                        }

                        $allocationAmount = min($remaining, $componentRemaining);

                        TransactionAllocation::create([
                            'bank_transaction_id' => $transaction->id,
                            'order_component_id' => $component->id,
                            'allocated_amount' => round($allocationAmount, 2),
                            'allocation_type' => 'automatic',
                            'match_confidence' => 100,
                            'notes' => null,
                            'metadata' => [],
                        ]);

                        $remaining = round($remaining - $allocationAmount, 2);
                    }

                    $transaction->refresh();

                    if (abs($transaction->remaining_amount) >= 0.01) {
                        throw new \RuntimeException('Transaction was not fully allocated.');
                    }

                    $transaction->markMatched();
                }

                $order->refresh();
                $order->load(['components.allocations']);

                if ($this->orderRemainingAmount($order) >= 0.01) {
                    throw new \RuntimeException('Order was not fully allocated.');
                }

                $order->markReconciled();
            });
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, BankTransaction>  $candidates
     * @return Collection<int, BankTransaction>|null
     */
    protected function findUniqueExactSubset(Collection $candidates, int $targetCents): ?Collection
    {
        $items = $candidates->values();
        $solutions = [];

        $this->searchExactSubsets($items, $targetCents, 0, [], $solutions);

        if (count($solutions) !== 1) {
            return null;
        }

        return collect($solutions[0])->values();
    }

    /**
     * @param  Collection<int, BankTransaction>  $items
     * @param  list<BankTransaction>  $current
     * @param  list<list<BankTransaction>>  $solutions
     */
    protected function searchExactSubsets(
        Collection $items,
        int $remainingCents,
        int $startIndex,
        array $current,
        array &$solutions,
    ): void {
        if (count($solutions) > 1) {
            return;
        }

        if ($remainingCents === 0) {
            if ($current !== []) {
                $solutions[] = $current;
            }

            return;
        }

        if ($remainingCents < 0) {
            return;
        }

        for ($index = $startIndex; $index < $items->count(); $index++) {
            if (count($solutions) > 1) {
                return;
            }

            $transaction = $items[$index];
            $amountCents = $this->toCents(abs((float) $transaction->amount));

            if ($amountCents > $remainingCents) {
                continue;
            }

            $current[] = $transaction;
            $this->searchExactSubsets(
                $items,
                $remainingCents - $amountCents,
                $index + 1,
                $current,
                $solutions,
            );
            array_pop($current);
        }
    }

    /**
     * @return array{min: Carbon, max: Carbon}|null
     */
    protected function postedAtRange(int $userId): ?array
    {
        $min = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('posted_at')
            ->min('posted_at');

        $max = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('posted_at')
            ->max('posted_at');

        if ($min === null || $max === null) {
            return null;
        }

        return [
            'min' => Carbon::parse($min)->startOfDay(),
            'max' => Carbon::parse($max)->startOfDay(),
        ];
    }

    /**
     * @param  array{min: Carbon, max: Carbon}|null  $range
     */
    protected function isNearImportEdge(Order $order, ?array $range): bool
    {
        $orderDate = $this->orderDate($order);

        if ($range === null) {
            return true;
        }

        return $orderDate->lt($range['min']) || $orderDate->gt($range['max']);
    }

    protected function orderRemainingAmount(Order $order): float
    {
        return max(0, round((float) $order->total - (float) $order->allocated_amount, 2));
    }

    protected function postedAtDate(BankTransaction $transaction): Carbon
    {
        return Carbon::parse($transaction->posted_at)->startOfDay();
    }

    protected function orderDate(Order $order): ?Carbon
    {
        $date = $order->ordered_at ?? $order->delivered_at;

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    protected function datesAlign(Carbon $postedAt, Carbon $orderDate): bool
    {
        return abs($postedAt->diffInDays($orderDate, false)) <= $this->dateWindowDays;
    }

    protected function paymentInstrumentsAlign(Order $order, BankTransaction $transaction): bool
    {
        if ($order->payment_last_four === null || $transaction->card_last_four === null) {
            return true;
        }

        return $order->payment_last_four === $transaction->card_last_four;
    }

    protected function amountsEqual(float $left, float $right): bool
    {
        return abs($left - $right) < 0.01;
    }

    protected function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
