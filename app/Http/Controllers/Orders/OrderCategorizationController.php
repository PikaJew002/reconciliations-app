<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\Product;
use App\Services\Orders\OrderCategorizationBrowseService;
use App\Services\Reconciliation\ProductMatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderCategorizationController extends Controller
{
    public function index(Request $request, OrderCategorizationBrowseService $browse): Response
    {
        $data = $browse->index(
            $request->user()->id,
            $request->string('q')->toString() ?: null,
        );

        return Inertia::render('Orders/Categorize', $data);
    }

    public function categorizeAll(
        Request $request,
        Order $order,
        ProductMatchingService $productMatching,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('kind', Category::KIND_EXPENSE)),
            ],
        ]);

        $categoryId = (int) $validated['category_id'];
        $order->loadMissing(['merchant', 'items.product']);

        $normalized = $order->merchant?->normalized_name;
        $updated = 0;

        if ($normalized === 'walmart') {
            $updated = $this->categorizeWalmartOrder($order, $categoryId, $productMatching);
        } elseif ($normalized === 'amazon') {
            $updated = $this->categorizeAmazonOrder($order, $categoryId);
        } else {
            abort(404);
        }

        return redirect()
            ->back(fallback: route('orders.categorize'))
            ->with(
                'success',
                $updated === 0
                    ? 'Nothing left to categorize on this order.'
                    : sprintf('Categorized %d %s on this order.', $updated, $updated === 1 ? 'line' : 'lines'),
            );
    }

    protected function categorizeWalmartOrder(
        Order $order,
        int $categoryId,
        ProductMatchingService $productMatching,
    ): int {
        $updated = 0;
        $categorizedProductIds = [];

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                $result = $productMatching->linkOrCreateForItem($item);

                if ($result === null) {
                    continue;
                }

                $product = $result['product'];
                $this->applyProductCategory($product, $categoryId);
                $categorizedProductIds[$product->id] = true;
                $updated++;

                continue;
            }

            $product = $item->product;

            if ($product === null || $product->category_id !== null) {
                continue;
            }

            if (isset($categorizedProductIds[$product->id])) {
                $updated++;

                continue;
            }

            $this->applyProductCategory($product, $categoryId);
            $categorizedProductIds[$product->id] = true;
            $updated++;
        }

        return $updated;
    }

    protected function categorizeAmazonOrder(Order $order, int $categoryId): int
    {
        return OrderComponent::query()
            ->where('order_id', $order->id)
            ->where('type', 'product')
            ->whereNull('category_id')
            ->update([
                'category_id' => $categoryId,
                'category_confidence' => 100,
                'is_user_modified' => true,
            ]);
    }

    protected function applyProductCategory(Product $product, int $categoryId): void
    {
        $product->update([
            'category_id' => $categoryId,
            'category_confidence' => 100,
            'is_user_modified' => true,
        ]);

        OrderComponent::query()
            ->whereNull('category_id')
            ->where('type', 'product')
            ->whereHas('orderItem', fn ($query) => $query->where('product_id', $product->id))
            ->update([
                'category_id' => $categoryId,
                'category_confidence' => 100,
            ]);
    }
}
