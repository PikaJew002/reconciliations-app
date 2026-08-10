<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderComponent;
use App\Models\Product;
use App\Services\Reconciliation\ProductMatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $products = Product::query()
            ->where('user_id', $userId)
            ->whereNull('category_id')
            ->with('merchant:id,name,normalized_name')
            ->withCount('orderItems')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'order_items_count' => $product->order_items_count,
                'merchant' => $product->merchant ? [
                    'id' => $product->merchant->id,
                    'name' => $product->merchant->name,
                    'normalized_name' => $product->merchant->normalized_name,
                ] : null,
            ]);

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('kind', Category::KIND_EXPENSE)
            ->orderBy('name')
            ->get(['id', 'name', 'kind']);

        return Inertia::render('Products/Index', [
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    public function reconcile(
        Request $request,
        ProductMatchingService $productMatching,
    ): RedirectResponse {
        $result = $productMatching->matchForUser($request->user()->id);

        return redirect()
            ->route('products.index')
            ->with(
                'success',
                sprintf(
                    'Product reconciliation finished: %d created, %d linked.',
                    $result['created'],
                    $result['linked'],
                ),
            );
    }

    public function updateCategory(Request $request, Product $product): RedirectResponse
    {
        $this->ensureOwned($request, $product);

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('kind', Category::KIND_EXPENSE)),
            ],
        ]);

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
            ->route('products.index')
            ->with('success', 'Product categorized.');
    }

    private function ensureOwned(Request $request, Product $product): void
    {
        if ($product->user_id !== $request->user()->id) {
            throw new NotFoundHttpException();
        }
    }
}
