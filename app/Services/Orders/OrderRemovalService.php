<?php

namespace App\Services\Orders;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderRemovalService
{
    public function remove(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order->load(['components.allocations']);

            $transactionIds = $order->components
                ->flatMap(fn (OrderComponent $component) => $component->allocations->pluck('bank_transaction_id'))
                ->unique()
                ->filter()
                ->values();

            $order->delete();

            $this->unwindOrphanedTransactions($transactionIds);
        });
    }

    /**
     * @param  iterable<int|string|null>  $transactionIds
     */
    public function refreshTransactionsAfterLineRemoval(iterable $transactionIds): void
    {
        $ids = $this->uniqueTransactionIds($transactionIds);

        $this->unwindOrphanedTransactions($ids);

        if ($ids->isEmpty()) {
            return;
        }

        BankTransaction::query()
            ->whereIn('id', $ids)
            ->get()
            ->each(function (BankTransaction $transaction): void {
                if ($transaction->status === 'matched' && ! $transaction->is_fully_allocated) {
                    $transaction->markPartial();
                }
            });
    }

    /**
     * @param  iterable<int|string|null>  $transactionIds
     */
    public function unwindOrphanedTransactions(iterable $transactionIds): void
    {
        $ids = $this->uniqueTransactionIds($transactionIds);

        if ($ids->isEmpty()) {
            return;
        }

        BankTransaction::query()
            ->whereIn('id', $ids)
            ->get()
            ->each(function (BankTransaction $transaction): void {
                if ($transaction->allocations()->exists()) {
                    return;
                }

                $transaction->update([
                    'status' => 'unmatched',
                ]);
            });
    }

    /**
     * @param  iterable<int|string|null>  $transactionIds
     * @return Collection<int, int|string>
     */
    protected function uniqueTransactionIds(iterable $transactionIds): Collection
    {
        return Collection::make($transactionIds)->filter()->unique()->values();
    }

    /**
     * Drop every allocation on the order so a refund or bank-total edit can
     * be reconciled again. Off-book tender rows created for gift cards are
     * removed; real bank lines go back to unmatched.
     */
    public function unwindAllocations(Order $order): void
    {
        $order->load(['components.allocations']);

        $transactionIds = $order->components
            ->flatMap(fn (OrderComponent $component) => $component->allocations->pluck('bank_transaction_id'))
            ->unique()
            ->filter()
            ->values();

        TransactionAllocation::query()
            ->whereIn('order_component_id', $order->components->modelKeys())
            ->delete();

        $this->releaseUnwoundTransactions($transactionIds);

        if ($order->status === 'reconciled') {
            $order->update([
                'status' => 'imported',
            ]);
        }

        $order->unsetRelation('components');
    }

    /**
     * @param  iterable<int|string|null>  $transactionIds
     */
    protected function releaseUnwoundTransactions(iterable $transactionIds): void
    {
        $ids = $this->uniqueTransactionIds($transactionIds);

        if ($ids->isEmpty()) {
            return;
        }

        BankTransaction::query()
            ->whereIn('id', $ids)
            ->get()
            ->each(function (BankTransaction $transaction): void {
                if ($transaction->allocations()->exists()) {
                    if ($transaction->status === 'matched' && ! $transaction->is_fully_allocated) {
                        $transaction->markPartial();
                    }

                    return;
                }

                if (($transaction->metadata['source'] ?? null) === 'non_bank_tender') {
                    $transaction->delete();

                    return;
                }

                $transaction->update([
                    'status' => 'unmatched',
                ]);
            });
    }

    public function reopenIfUnbalanced(Order $order): void
    {
        $order->refresh();
        $order->unsetRelation('components');
        $order->load('components');

        $componentSum = $order->payableComponentSum();
        $total = round((float) $order->total, 2);

        if ($order->status === 'reconciled' && abs($total - $componentSum) >= 0.01) {
            $order->update([
                'status' => 'imported',
            ]);
        }
    }
}
