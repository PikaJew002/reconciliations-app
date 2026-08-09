<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\TransactionAllocation;
use Illuminate\Support\Facades\DB;

class RemoveSyntheticBankSpendService
{
    /**
     * @return array{orders_deleted: int, transactions_reset: int}
     */
    public function remove(?int $userId = null): array
    {
        $ordersDeleted = 0;
        $transactionsReset = 0;

        $query = Order::query()
            ->where('metadata->source', 'bank_synthetic')
            ->when($userId !== null, fn ($builder) => $builder->where('user_id', $userId))
            ->orderBy('id');

        $query->each(function (Order $order) use (&$ordersDeleted, &$transactionsReset): void {
            DB::transaction(function () use ($order, &$ordersDeleted, &$transactionsReset): void {
                $bankTransactionId = data_get($order->metadata, 'bank_transaction_id');

                $componentIds = $order->components()->pluck('id');

                if ($componentIds->isNotEmpty()) {
                    TransactionAllocation::query()
                        ->whereIn('order_component_id', $componentIds)
                        ->delete();
                }

                $order->components()->delete();
                $order->delete();
                $ordersDeleted++;

                if ($bankTransactionId === null) {
                    return;
                }

                $transaction = BankTransaction::query()->find($bankTransactionId);

                if (! $transaction) {
                    return;
                }

                if ($transaction->allocations()->exists()) {
                    return;
                }

                $transaction->update([
                    'status' => 'unmatched',
                ]);
                $transactionsReset++;
            });
        });

        return [
            'orders_deleted' => $ordersDeleted,
            'transactions_reset' => $transactionsReset,
        ];
    }
}
