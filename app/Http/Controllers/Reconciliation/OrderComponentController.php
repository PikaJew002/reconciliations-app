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
    public function store(
        StoreOrderComponentRequest $request,
        Order $order,
        OrderRemovalService $removal,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);

        $releasedMatch = $order->status === 'reconciled';

        DB::transaction(function () use ($request, $order, $removal, $releasedMatch): void {
            if ($releasedMatch) {
                $removal->unwindAllocations($order);
            }

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
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', $releasedMatch
                ? 'Component added. The bank match was released so reconciliation can run again.'
                : 'Component added. Re-run reconciliation when ready.');
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
        $releasedMatch = $order->status === 'reconciled';

        DB::transaction(function () use ($order, $components, $transactionIds, $removal, $releasedMatch): void {
            if ($releasedMatch) {
                $removal->unwindAllocations($order);
            }

            foreach ($components as $row) {
                $row->delete();
            }

            $removal->reopenIfUnbalanced($order);
            $removal->refreshTransactionsAfterLineRemoval($transactionIds);
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', $releasedMatch
                ? 'Component removed. The bank match was released so reconciliation can run again.'
                : 'Component removed.');
    }
}
