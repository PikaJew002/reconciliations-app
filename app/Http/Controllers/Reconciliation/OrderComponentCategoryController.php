<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderComponent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderComponentCategoryController extends Controller
{
    public function update(
        Request $request,
        Order $order,
        OrderComponent $component,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($component->order_id === $order->id, 404);

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('kind', Category::KIND_EXPENSE)),
            ],
        ]);

        $component->update([
            'category_id' => $validated['category_id'],
            'category_confidence' => 100,
            'is_user_modified' => true,
        ]);

        if ($component->isProduct()) {
            $component->loadMissing('orderItem.product');
            $product = $component->orderItem?->product;

            if ($product && $product->user_id === $request->user()->id) {
                $product->update([
                    'category_id' => $validated['category_id'],
                    'category_confidence' => 100,
                    'is_user_modified' => true,
                ]);
            }
        }

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Component categorized.');
    }
}
