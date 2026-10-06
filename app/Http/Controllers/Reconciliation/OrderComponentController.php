<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\StoreOrderComponentRequest;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Services\Orders\OrderComponentSelection;
use App\Services\Orders\OrderRemovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Component added. Re-run reconciliation when ready.');
    }

    public function destroy(
        Request $request,
        Order $order,
        OrderComponent $component,
        OrderRemovalService $removal,
        OrderComponentSelection $selection,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($component->order_id === $order->id, 404);

        $components = $selection->resolve($request, $order, $component);
        $components->load('allocations');
        $transactionIds = $components->flatMap(
            fn (OrderComponent $row) => $row->allocations->pluck('bank_transaction_id'),
        );

        DB::transaction(function () use ($order, $components, $transactionIds, $removal): void {
            foreach ($components as $row) {
                $row->delete();
            }

            $removal->reopenIfUnbalanced($order);
            $removal->refreshTransactionsAfterLineRemoval($transactionIds);
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Component removed.');
    }
}
