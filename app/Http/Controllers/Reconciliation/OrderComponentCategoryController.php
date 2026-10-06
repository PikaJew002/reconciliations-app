<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Services\Orders\OrderComponentSelection;
use App\Services\Plans\VacationWindowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderComponentCategoryController extends Controller
{
    public function update(
        Request $request,
        Order $order,
        OrderComponent $component,
        VacationWindowService $vacationWindows,
        OrderComponentSelection $selection,
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

        $components = $selection->resolve($request, $order, $component);
        $inVacationWindow = $vacationWindows->covers($order->user_id, $order->ordered_at);

        foreach ($components as $row) {
            $row->update([
                'category_id' => $validated['category_id'],
                'category_confidence' => 100,
                'is_user_modified' => true,
            ]);

            if (! $row->isProduct() || $inVacationWindow) {
                continue;
            }

            $row->loadMissing('orderItem.product');
            $product = $row->orderItem?->product;

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
