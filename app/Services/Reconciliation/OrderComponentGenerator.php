<?php

namespace App\Services\Reconciliation;

use App\Models\Order;
use App\Models\OrderComponent;

class OrderComponentGenerator
{
    /**
     * @return int Number of orders that had components generated.
     */
    public function generateForUser(int $userId): int
    {
        $count = 0;

        Order::query()
            ->where('user_id', $userId)
            ->whereDoesntHave('components')
            ->with('items')
            ->orderBy('id')
            ->each(function (Order $order) use (&$count): void {
                if ($this->generateForOrder($order)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return int Number of orders that had components generated.
     */
    public function generateForImportBatch(int $importBatchId): int
    {
        $count = 0;

        Order::query()
            ->where('import_batch_id', $importBatchId)
            ->whereDoesntHave('components')
            ->with('items')
            ->orderBy('id')
            ->each(function (Order $order) use (&$count): void {
                if ($this->generateForOrder($order)) {
                    $count++;
                }
            });

        return $count;
    }

    public function generateForOrder(Order $order): bool
    {
        if ($order->components()->exists()) {
            return false;
        }

        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $productCategoryId = $item->product?->category_id;

            OrderComponent::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'product',
                'description' => $item->description,
                'amount' => $item->extended_price,
                'category_id' => $productCategoryId,
                'category_confidence' => $productCategoryId !== null ? 100 : null,
                'is_user_modified' => false,
                'metadata' => [],
            ]);
        }

        $this->createOrderLevelComponent($order, 'tax', 'Sales Tax', (float) $order->tax);
        $this->createOrderLevelComponent($order, 'delivery', 'Delivery Fee', (float) $order->delivery_fee);
        $this->createOrderLevelComponent($order, 'tip', 'Driver Tip', (float) $order->tip);
        $this->createOrderLevelComponent($order, 'discount', 'Discount', -abs((float) $order->discount));

        return true;
    }

    protected function createOrderLevelComponent(Order $order, string $type, string $description, float $amount): void
    {
        if (abs($amount) < 0.01) {
            return;
        }

        OrderComponent::create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => $type,
            'description' => $description,
            'amount' => round($amount, 2),
            'category_id' => null,
            'category_confidence' => null,
            'is_user_modified' => false,
            'metadata' => [],
        ]);
    }
}
