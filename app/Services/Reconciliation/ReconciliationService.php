<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\TransactionAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReconciliationService
{
    public function __construct(
        protected int $dateWindowDays = 7,
    ) {}

    /**
     * @return int Number of bank transactions reconciled (fully or partially).
     */
    public function reconcileForUser(int $userId): int
    {
        $count = 0;

        BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['unmatched', 'partial'])
            ->whereNotNull('merchant_id')
            ->where('amount', '<', 0)
            ->with(['allocations'])
            ->orderBy('posted_at')
            ->orderBy('id')
            ->each(function (BankTransaction $transaction) use ($userId, &$count): void {
                if ($this->reconcileTransaction($transaction, $userId)) {
                    $count++;
                }
            });

        return $count;
    }

    public function reconcileTransaction(BankTransaction $transaction, int $userId): bool
    {
        if ($transaction->merchant_id === null || (float) $transaction->amount >= 0) {
            return false;
        }

        if (abs($transaction->remaining_amount) < 0.01) {
            return false;
        }

        $order = $this->findMatchingOrder($transaction, $userId);

        if (! $order) {
            return false;
        }

        $this->allocateTransactionToOrder($transaction, $order);

        return true;
    }

    protected function findMatchingOrder(BankTransaction $transaction, int $userId): ?Order
    {
        $transactionAmount = abs((float) $transaction->amount);
        $transactionDate = $this->transactionDate($transaction);

        $candidates = Order::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $transaction->merchant_id)
            ->where('status', '!=', 'reconciled')
            ->with(['components.allocations'])
            ->get()
            ->filter(function (Order $order) use ($transaction, $transactionAmount, $transactionDate): bool {
                if (! $this->datesAlign($transactionDate, $order)) {
                    return false;
                }

                if (! $this->paymentInstrumentsAlign($order, $transaction)) {
                    return false;
                }

                $remaining = $this->orderRemainingAmount($order);

                if ($remaining < 0.01) {
                    return false;
                }

                if ($this->amountsEqual($transactionAmount, (float) $order->total)) {
                    return true;
                }

                return $transactionAmount <= $remaining + 0.01;
            });

        if ($candidates->isEmpty()) {
            return null;
        }

        $exactMatches = $candidates->filter(
            fn (Order $order): bool => $this->amountsEqual($transactionAmount, (float) $order->total),
        );

        if ($exactMatches->count() === 1) {
            return $exactMatches->first();
        }

        if ($exactMatches->count() > 1) {
            return $this->closestByDate($exactMatches, $transactionDate);
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return $this->closestByDate($candidates, $transactionDate);
    }

    protected function allocateTransactionToOrder(BankTransaction $transaction, Order $order): void
    {
        $remaining = abs((float) $transaction->remaining_amount);

        $order->loadMissing(['components.allocations']);

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
        $order->refresh();

        if (abs($transaction->remaining_amount) < 0.01) {
            $transaction->markMatched();
        } else {
            $transaction->markPartial();
        }

        $order->load(['components.allocations']);

        if ($this->orderRemainingAmount($order) < 0.01) {
            $order->markReconciled();
        }
    }

    protected function orderRemainingAmount(Order $order): float
    {
        return max(0, round((float) $order->total - (float) $order->allocated_amount, 2));
    }

    protected function transactionDate(BankTransaction $transaction): Carbon
    {
        $date = $transaction->transaction_date ?? $transaction->posted_at;

        return Carbon::parse($date)->startOfDay();
    }

    protected function orderDate(Order $order): ?Carbon
    {
        $date = $order->ordered_at ?? $order->delivered_at;

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    protected function datesAlign(Carbon $transactionDate, Order $order): bool
    {
        $orderDate = $this->orderDate($order);

        if (! $orderDate) {
            return true;
        }

        return abs($transactionDate->diffInDays($orderDate, false)) <= $this->dateWindowDays;
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

    /**
     * @param  Collection<int, Order>  $candidates
     */
    protected function closestByDate(Collection $candidates, Carbon $transactionDate): ?Order
    {
        $scored = $candidates
            ->map(function (Order $order) use ($transactionDate): array {
                $orderDate = $this->orderDate($order);

                if (! $orderDate) {
                    return ['order' => $order, 'distance' => PHP_INT_MAX];
                }

                return [
                    'order' => $order,
                    'distance' => abs($transactionDate->diffInDays($orderDate, false)),
                ];
            })
            ->sortBy('distance')
            ->values();

        if ($scored->count() < 2) {
            return $scored->first()['order'] ?? null;
        }

        $best = $scored[0];
        $second = $scored[1];

        if ($best['distance'] === $second['distance']) {
            return null;
        }

        return $best['order'];
    }
}
