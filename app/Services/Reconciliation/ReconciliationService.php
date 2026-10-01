<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(
        protected PaymentInstrumentAligner $paymentInstruments,
        protected int $dateWindowDays = 7,
        protected int $preCoverageLookbackDays = 10,
        protected int $subsetCandidateCap = 12,
        protected int $refundCreditWindowDays = 90,
    ) {}

    /**
     * @return int Number of bank transactions matched.
     */
    public function reconcileForUser(int $userId): int
    {
        $matchedTransactionIds = [];

        $matchedTransactionIds = array_merge(
            $matchedTransactionIds,
            $this->reconcileBankRefunds($userId),
        );

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
            if ($order->bankRefundTotal() >= 0.01) {
                continue;
            }

            $candidates = $this->uniqueExactCharge($userId, $order);

            if ($candidates === null) {
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
            if ($order->bankRefundTotal() >= 0.01) {
                continue;
            }

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
     * A single real charge equal to the order total, on the order's card
     * (or a card alias). One charge in the whole history wins even when it
     * posts outside the 7-day window or on the last imported bank day.
     * Several charges fall back to that window so an older duplicate is not used.
     *
     * @return Collection<int, BankTransaction>|null
     */
    public function uniqueExactCharge(int $userId, Order $order): ?Collection
    {
        $exact = $this->candidateTransactions($userId, $order, false)
            ->filter(fn (BankTransaction $transaction): bool => $this->amountsEqual(
                abs((float) $transaction->amount),
                (float) $order->total,
            ))
            ->values();

        $cardIsKnown = $order->payment_last_four !== null && $order->payment_last_four !== '';

        if ($cardIsKnown && $exact->count() === 1) {
            return $exact;
        }

        $orderDate = $this->orderDate($order);
        $withinWindow = $exact
            ->filter(function (BankTransaction $transaction) use ($orderDate): bool {
                return $orderDate === null
                    || $this->datesAlign($this->postedAtDate($transaction), $orderDate);
            })
            ->values();

        if ($withinWindow->count() === 1) {
            return $withinWindow;
        }

        return null;
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    protected function candidateTransactions(int $userId, Order $order, bool $requireDateAlignment = true): Collection
    {
        $orderDate = $this->orderDate($order);

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $order->merchant_id)
            ->availableForExpenseMatching()
            ->where('amount', '<', 0)
            ->whereNotNull('merchant_id')
            ->where(function ($query): void {
                $query->whereNull('metadata->source')
                    ->orWhere('metadata->source', '!=', 'non_bank_tender');
            })
            ->with(['account.cardAliases'])
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get()
            ->filter(function (BankTransaction $transaction) use ($order, $orderDate, $requireDateAlignment): bool {
                if (! $this->paymentInstrumentsAlign($order, $transaction)) {
                    return false;
                }

                if (! $requireDateAlignment || $orderDate === null) {
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
                    $this->allocateDebitAcrossComponents($transaction, $order);
                }

                $order->refresh();
                $order->load(['components.allocations']);

                if ($this->orderRemainingAmount($order) >= 0.01) {
                    throw new \RuntimeException('Order was not fully allocated.');
                }

                $order->markReconciled();
            });
        } catch (\RuntimeException $exception) {
            $this->rethrowDatabaseException($exception);

            return false;
        }

        return true;
    }

    /**
     * Match a gross card charge and the refund credits that net to the bank total.
     *
     * @return list<int>
     */
    protected function reconcileBankRefunds(int $userId): array
    {
        $matchedTransactionIds = [];
        $paymentResolution = app(OrderPaymentResolutionService::class);

        foreach ($this->openOrders($userId) as $order) {
            $bankRefund = $order->bankRefundTotal();

            if ($bankRefund < 0.01) {
                continue;
            }

            if (abs($order->payableComponentSum() - (float) $order->total) >= 0.01) {
                continue;
            }

            if ($paymentResolution->blocksRefundMatching($order)) {
                continue;
            }

            $offBook = $paymentResolution->offBookPaymentTotal($order);
            $expectedDebit = round((float) $order->total + $bankRefund - $offBook, 2);

            if ($expectedDebit < 0.01) {
                continue;
            }

            $debits = $this->candidateRefundDebitTransactions($userId, $order)->values();
            $credits = $this->candidateCreditTransactions($userId, $order)->values();

            if (
                $debits->isEmpty()
                || $credits->isEmpty()
                || $debits->count() > $this->subsetCandidateCap
                || $credits->count() > $this->subsetCandidateCap
            ) {
                continue;
            }

            $debitSubset = $this->findUniqueExactSubset($debits, $this->toCents($expectedDebit));
            $creditSubset = $this->findUniqueExactSubset($credits, $this->toCents($bankRefund));

            if ($debitSubset === null || $creditSubset === null) {
                continue;
            }

            $matched = $debitSubset->concat($creditSubset)->values();

            if ($this->allocateBankRefundTransactions($matched, $order)) {
                foreach ($matched as $transaction) {
                    $matchedTransactionIds[] = $transaction->id;
                }
            }
        }

        return $matchedTransactionIds;
    }

    /**
     * Unmatched charges for a refund, with no post-date window.
     * The original debit often posts within a week, while the credit can
     * arrive months later; the 7-day window stays on ordinary matching.
     *
     * @return Collection<int, BankTransaction>
     */
    protected function candidateRefundDebitTransactions(int $userId, Order $order): Collection
    {
        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $order->merchant_id)
            ->availableForExpenseMatching()
            ->where('amount', '<', 0)
            ->whereNotNull('merchant_id')
            ->where(function ($query): void {
                $query->whereNull('metadata->source')
                    ->orWhere('metadata->source', '!=', 'non_bank_tender');
            })
            ->with(['account.cardAliases'])
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankTransaction $transaction): bool => $this->paymentInstrumentsAlign($order, $transaction))
            ->values();
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    protected function candidateCreditTransactions(int $userId, Order $order): Collection
    {
        $orderDate = $this->orderDate($order);

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $order->merchant_id)
            ->availableForExpenseMatching()
            ->where('amount', '>', 0)
            ->whereNotNull('merchant_id')
            ->with(['account.cardAliases'])
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

                return abs($this->postedAtDate($transaction)->diffInDays($orderDate, false)) <= $this->refundCreditWindowDays;
            })
            ->values();
    }

    /**
     * @param  Collection<int, BankTransaction>  $transactions
     */
    protected function allocateBankRefundTransactions(Collection $transactions, Order $order): bool
    {
        $debits = $transactions
            ->filter(fn (BankTransaction $transaction): bool => (float) $transaction->amount < 0)
            ->sortBy('id')
            ->values();
        $credits = $transactions
            ->filter(fn (BankTransaction $transaction): bool => (float) $transaction->amount > 0)
            ->sortBy('id')
            ->values();

        $order->loadMissing('components');
        $bankRefund = $order->bankRefundTotal();
        $paymentResolution = app(OrderPaymentResolutionService::class);
        $offBook = $paymentResolution->offBookPaymentTotal($order);
        $debitSum = round($debits->sum(fn (BankTransaction $transaction): float => abs((float) $transaction->amount)), 2);
        $creditSum = round($credits->sum(fn (BankTransaction $transaction): float => (float) $transaction->amount), 2);
        $expectedDebit = round((float) $order->total + $bankRefund - $offBook, 2);

        if (
            $debits->isEmpty()
            || $credits->isEmpty()
            || $expectedDebit < 0.01
            || abs($debitSum - $expectedDebit) >= 0.01
            || abs($creditSum - $bankRefund) >= 0.01
            || abs($order->payableComponentSum() - (float) $order->total) >= 0.01
        ) {
            return false;
        }

        try {
            DB::transaction(function () use ($debits, $credits, $order, $paymentResolution): void {
                $order->refresh();
                $order->load(['components.allocations']);

                $hasAllocations = $order->components->contains(
                    fn (OrderComponent $component): bool => $component->allocations->isNotEmpty(),
                );

                if ($order->status === 'reconciled' || $hasAllocations) {
                    throw new \RuntimeException('Order is not allocatable.');
                }

                foreach ($debits->concat($credits) as $transaction) {
                    $transaction->refresh();

                    if ($transaction->status !== 'unmatched' || abs((float) $transaction->remaining_amount) < 0.01) {
                        throw new \RuntimeException('Transaction is not allocatable.');
                    }
                }

                foreach ($paymentResolution->createOffBookTenderDebits($order) as $synthetic) {
                    $this->allocateDebitAcrossComponents($synthetic, $order);
                }

                foreach ($debits as $transaction) {
                    $this->allocateDebitAcrossComponents($transaction, $order);
                }

                $this->allocateRefundCredits($credits, $order);

                $order->refresh();
                $net = round((float) $order->allocated_amount, 2);

                if (abs($net - round((float) $order->total, 2)) >= 0.01) {
                    throw new \RuntimeException('Order was not fully allocated.');
                }

                $order->markReconciled();
            });
        } catch (\RuntimeException $exception) {
            $this->rethrowDatabaseException($exception);

            return false;
        }

        return true;
    }

    protected function rethrowDatabaseException(\RuntimeException $exception): void
    {
        if ($exception instanceof QueryException || $exception instanceof \PDOException) {
            throw $exception;
        }
    }

    protected function allocateDebitAcrossComponents(BankTransaction $transaction, Order $order): void
    {
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
                'allocation_type' => TransactionAllocation::TYPE_AUTOMATIC,
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

    /**
     * @param  Collection<int, BankTransaction>  $credits
     */
    protected function allocateRefundCredits(Collection $credits, Order $order): void
    {
        $order->load(['components']);

        $capacity = [];

        foreach ($order->components->sortBy('id') as $component) {
            if ($component->refund_kind !== OrderComponent::REFUND_KIND_BANK) {
                continue;
            }

            $capacity[$component->id] = round((float) $component->refund_amount, 2);
        }

        foreach ($credits as $credit) {
            $remaining = round((float) $credit->amount, 2);

            foreach ($capacity as $componentId => $room) {
                if ($remaining < 0.01) {
                    break;
                }

                if ($room < 0.01) {
                    continue;
                }

                $allocationAmount = round(min($remaining, $room), 2);

                TransactionAllocation::create([
                    'bank_transaction_id' => $credit->id,
                    'order_component_id' => $componentId,
                    'allocated_amount' => $allocationAmount,
                    'allocation_type' => TransactionAllocation::TYPE_REFUND,
                    'match_confidence' => 100,
                    'notes' => null,
                    'metadata' => [],
                ]);

                $capacity[$componentId] = round($room - $allocationAmount, 2);
                $remaining = round($remaining - $allocationAmount, 2);
            }

            $credit->refresh();

            if ($remaining >= 0.01 || abs((float) $credit->remaining_amount) >= 0.01) {
                throw new \RuntimeException('Refund credit was not fully allocated.');
            }

            $credit->markMatched();
        }
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

        $earliestAllowed = $range['min']->copy()->subDays($this->preCoverageLookbackDays);

        return $orderDate->lt($earliestAllowed) || $orderDate->gt($range['max']);
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
        return $this->paymentInstruments->align($order->payment_last_four, $transaction);
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
