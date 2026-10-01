<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\UpdateOrderComponentRefundRequest;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Services\Orders\OrderRemovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderComponentRefundController extends Controller
{
    public function update(
        UpdateOrderComponentRefundRequest $request,
        Order $order,
        OrderComponent $component,
        OrderRemovalService $removal,
    ): RedirectResponse {
        $this->guardEditable($request, $order, $component);

        DB::transaction(function () use ($request, $order, $component, $removal): void {
            $this->reopenReconciledOrder($order, $removal);

            $component->update([
                'refund_amount' => round((float) $request->input('refund_amount'), 2),
                'refund_kind' => $request->string('refund_kind')->toString(),
                'is_user_modified' => true,
            ]);
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Component marked refunded.');
    }

    public function destroy(
        Request $request,
        Order $order,
        OrderComponent $component,
        OrderRemovalService $removal,
    ): RedirectResponse {
        $this->guardEditable($request, $order, $component);

        DB::transaction(function () use ($order, $component, $removal): void {
            $this->reopenReconciledOrder($order, $removal);

            $component->update([
                'refund_amount' => null,
                'refund_kind' => null,
            ]);
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Refund cleared.');
    }

    protected function guardEditable(Request $request, Order $order, OrderComponent $component): void
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($component->order_id === $order->id, 404);

        if ($order->status === 'reconciled') {
            return;
        }

        abort_if(
            $component->allocations()->exists(),
            422,
            'Allocated components cannot be edited.',
        );
    }

    protected function reopenReconciledOrder(Order $order, OrderRemovalService $removal): void
    {
        if ($order->status !== 'reconciled') {
            return;
        }

        $removal->unwindAllocations($order);
    }
}
