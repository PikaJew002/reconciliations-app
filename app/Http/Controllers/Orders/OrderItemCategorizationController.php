<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\ProductMatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderItemCategorizationController extends Controller
{
    public function store(
        Request $request,
        OrderItem $item,
        ProductMatchingService $productMatching,
    ): RedirectResponse {
        $this->authorizeWalmartItem($request, $item);

        $validated = ['category_id' => $this->validatedExpenseCategoryId($request)];

        $result = $productMatching->linkOrCreateForItem($item);

        if ($result === null) {
            throw new NotFoundHttpException;
        }

        $product = $result['product'];

        $product->update([
            'category_id' => $validated['category_id'],
            'category_confidence' => 100,
            'is_user_modified' => true,
        ]);

        OrderComponent::query()
            ->whereNull('category_id')
            ->where('type', 'product')
            ->whereHas('orderItem', fn ($query) => $query->where('product_id', $product->id))
            ->update([
                'category_id' => $validated['category_id'],
                'category_confidence' => 100,
            ]);

        return redirect()
            ->back(fallback: route('orders.categorize'))
            ->with('success', 'Product created and categorized.');
    }

    public function storeInstance(
        Request $request,
        OrderItem $item,
        ProductMatchingService $productMatching,
        OrderComponentGenerator $componentGenerator,
    ): RedirectResponse {
        $this->authorizeWalmartItem($request, $item);

        $categoryId = $this->validatedExpenseCategoryId($request);

        $result = $productMatching->linkOrCreateForItem($item);

        if ($result === null) {
            throw new NotFoundHttpException;
        }

        $item->refresh();
        $item->loadMissing('order');

        $order = $item->order;

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        if (! $order->components()->exists()) {
            $componentGenerator->generateForOrder($order);
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

        return redirect()
            ->back(fallback: route('orders.categorize'))
            ->with('success', 'Line categorized for this order only.');
    }

    public function destroy(Request $request, OrderItem $item): RedirectResponse
    {
        $item->loadMissing(['order', 'product', 'components.allocations']);

        $order = $item->order;

        if ($order === null || $order->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        abort_if($order->status === 'reconciled', 422, 'Reconciled orders cannot be edited.');

        abort_if(
            $item->components->contains(
                fn (OrderComponent $component): bool => $component->allocations->isNotEmpty(),
            ),
            422,
            'Allocated line items cannot be removed.',
        );

        $hadProduct = $item->product_id !== null;
        $productId = $item->product_id;
        $deletedProduct = false;

        DB::transaction(function () use ($item, $productId, &$deletedProduct): void {
            OrderComponent::query()
                ->where('order_item_id', $item->id)
                ->delete();

            $item->delete();

            if ($productId === null) {
                return;
            }

            $stillLinked = OrderItem::query()
                ->where('product_id', $productId)
                ->exists();

            if (! $stillLinked) {
                Product::query()->whereKey($productId)->delete();
                $deletedProduct = true;
            }
        });

        $message = match (true) {
            $deletedProduct => 'Line and product removed.',
            $hadProduct => 'Line removed. Product kept because other orders still use it.',
            default => 'Line removed from order.',
        };

        return redirect()
            ->back(fallback: route('orders.categorize'))
            ->with('success', $message);
    }

    protected function authorizeWalmartItem(Request $request, OrderItem $item): void
    {
        $item->loadMissing('order.merchant');

        $order = $item->order;

        if ($order === null || $order->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        if ($order->merchant?->normalized_name !== 'walmart') {
            throw new NotFoundHttpException;
        }
    }

    protected function validatedExpenseCategoryId(Request $request): int
    {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('kind', Category::KIND_EXPENSE)),
            ],
        ]);

        return (int) $validated['category_id'];
    }
}
