<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\UpdateOrderItemRequest;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrderItemController extends Controller
{
    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($item->order_id === $order->id, 404);
        abort_if($order->status === 'reconciled', 422, 'Reconciled orders cannot be edited.');

        $quantity = round((float) $request->input('quantity'), 3);
        $extendedPrice = round((float) $item->unit_price * $quantity, 2);

        $productComponents = OrderComponent::query()
            ->where('order_id', $order->id)
            ->where('order_item_id', $item->id)
            ->where('type', 'product')
            ->withCount('allocations')
            ->get();

        abort_if(
            $productComponents->contains(fn (OrderComponent $component): bool => (int) $component->allocations_count > 0),
            422,
            'Allocated line items cannot be edited.',
        );

        DB::transaction(function () use ($item, $quantity, $extendedPrice, $productComponents): void {
            $item->update([
                'quantity' => $quantity,
                'extended_price' => $extendedPrice,
            ]);

            foreach ($productComponents as $component) {
                $component->update([
                    'amount' => $extendedPrice,
                    'is_user_modified' => true,
                ]);
            }
        });

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Item quantity updated. Re-run reconciliation when ready.');
    }
}
