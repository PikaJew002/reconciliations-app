<?php

namespace App\Services\Orders;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\OrderComponent;
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

            if ($transactionIds->isEmpty()) {
                return;
            }

            BankTransaction::query()
                ->whereIn('id', $transactionIds)
                ->get()
                ->each(function (BankTransaction $transaction): void {
                    if ($transaction->allocations()->exists()) {
                        return;
                    }

                    $transaction->update([
                        'status' => 'unmatched',
                    ]);
                });
        });
    }
}
