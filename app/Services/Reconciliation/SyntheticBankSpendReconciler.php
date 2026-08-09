<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use Illuminate\Support\Facades\DB;

class SyntheticBankSpendReconciler
{
    /**
     * @return int Number of bank transactions reconciled via synthetic orders.
     */
    public function reconcileForUser(int $userId): int
    {
        $count = 0;

        BankTransaction::query()
            ->where('user_id', $userId)
            ->availableForExpenseMatching()
            ->where('amount', '<', 0)
            ->whereNotNull('merchant_id')
            ->whereDoesntHave('allocations')
            ->with('merchant')
            ->orderBy('id')
            ->each(function (BankTransaction $transaction) use (&$count): void {
                if ($this->reconcileTransaction($transaction)) {
                    $count++;
                }
            });

        return $count;
    }

    public function reconcileTransaction(BankTransaction $transaction): bool
    {
        $transaction->loadMissing('merchant');

        if (
            $transaction->status !== 'unmatched'
            || $transaction->merchant_id === null
            || $transaction->merchant === null
            || (float) $transaction->amount >= 0
            || $transaction->allocations()->exists()
        ) {
            return false;
        }

        if ($transaction->merchant->supports_order_import) {
            return false;
        }

        if ($this->looksLikeAmazonMerchant($transaction->merchant->normalized_name)) {
            return false;
        }

        if ($this->existingSyntheticOrder($transaction) !== null) {
            return false;
        }

        $amount = round(abs((float) $transaction->amount), 2);

        DB::transaction(function () use ($transaction, $amount): void {
            $order = Order::query()->create([
                'user_id' => $transaction->user_id,
                'import_batch_id' => $transaction->import_batch_id,
                'merchant_id' => $transaction->merchant_id,
                'order_number' => 'SYN-BTX-'.$transaction->id,
                'ordered_at' => $transaction->posted_at,
                'fulfilled_at' => null,
                'delivered_at' => null,
                'subtotal' => $amount,
                'tax' => 0,
                'delivery_fee' => 0,
                'tip' => 0,
                'discount' => 0,
                'total' => $amount,
                'currency' => $transaction->currency ?? 'USD',
                'payment_last_four' => $transaction->card_last_four,
                'shipping_state' => null,
                'shipping_zip' => null,
                'status' => 'imported',
                'notes' => null,
                'metadata' => [
                    'source' => 'bank_synthetic',
                    'bank_transaction_id' => $transaction->id,
                ],
            ]);

            $component = OrderComponent::query()->create([
                'order_id' => $order->id,
                'order_item_id' => null,
                'type' => 'product',
                'description' => $transaction->merchant->name,
                'amount' => $amount,
                'category_id' => null,
                'category_confidence' => null,
                'is_user_modified' => false,
                'metadata' => [
                    'source' => 'bank_synthetic',
                ],
            ]);

            TransactionAllocation::query()->create([
                'bank_transaction_id' => $transaction->id,
                'order_component_id' => $component->id,
                'allocated_amount' => $amount,
                'allocation_type' => 'automatic',
                'match_confidence' => 100,
                'notes' => null,
                'metadata' => [
                    'source' => 'bank_synthetic',
                ],
            ]);

            $transaction->markMatched();
            $order->markReconciled();
        });

        return true;
    }

    protected function existingSyntheticOrder(BankTransaction $transaction): ?Order
    {
        return Order::query()
            ->where('user_id', $transaction->user_id)
            ->where('merchant_id', $transaction->merchant_id)
            ->where('metadata->source', 'bank_synthetic')
            ->where('metadata->bank_transaction_id', $transaction->id)
            ->first();
    }

    protected function looksLikeAmazonMerchant(string $normalizedName): bool
    {
        return str_contains($normalizedName, 'amazon') || str_contains($normalizedName, 'amzn');
    }
}
