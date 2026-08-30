<?php

namespace App\Services\Orders;

use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\ProductMatchingService;

class OrderInstanceCategorizationService
{
    public function __construct(
        protected ProductMatchingService $productMatching,
        protected OrderComponentGenerator $componentGenerator,
    ) {}

    public function categorizeItem(OrderItem $item, int $categoryId): bool
    {
        $result = $this->productMatching->linkOrCreateForItem($item);

        if ($result === null) {
            return false;
        }

        $item->refresh();
        $item->loadMissing('order');

        $order = $item->order;

        if ($order === null) {
            return false;
        }

        if (! $order->components()->exists()) {
            $this->componentGenerator->generateForOrder($order);
        }

        $updated = OrderComponent::query()
            ->where('order_item_id', $item->id)
            ->where('type', 'product')
            ->update([
                'category_id' => $categoryId,
                'category_confidence' => 100,
                'is_user_modified' => true,
            ]);

        if ($updated === 0) {
            OrderComponent::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'product',
                'description' => $item->description,
                'amount' => $item->extended_price,
                'category_id' => $categoryId,
                'category_confidence' => 100,
                'is_user_modified' => true,
                'metadata' => [],
            ]);
        }

        return true;
    }
}
