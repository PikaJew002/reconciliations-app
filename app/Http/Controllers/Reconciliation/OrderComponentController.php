<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\StoreOrderComponentRequest;
use App\Models\Order;
use App\Models\OrderComponent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderComponentController extends Controller
{
    public function store(StoreOrderComponentRequest $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_if($order->status === 'reconciled', 422, 'Reconciled orders cannot be edited.');

        $order->components()->create([
            'order_item_id' => null,
            'type' => $request->string('type')->toString(),
            'description' => $request->string('description')->toString(),
            'amount' => round((float) $request->input('amount'), 2),
            'category_id' => null,
            'category_confidence' => null,
            'is_user_modified' => true,
            'metadata' => [
                'source' => 'manual',
            ],
        ]);

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Component added. Re-run reconciliation when ready.');
    }

    public function destroy(Request $request, Order $order, OrderComponent $component): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($component->order_id === $order->id, 404);
        abort_if($order->status === 'reconciled', 422, 'Reconciled orders cannot be edited.');
        abort_if($component->allocations()->exists(), 422, 'Allocated components cannot be deleted.');

        $component->delete();

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Component removed.');
    }
}
