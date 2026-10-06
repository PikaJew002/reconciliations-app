<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\UpdateOrderComponentRefundRequest;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Services\Orders\OrderComponentSelection;
use App\Services\Orders\OrderRemovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderComponentRefundController extends Controller
{
    public function update(
        UpdateOrderComponentRefundRequest $request,
        Order $order,
        OrderComponent $component,
        OrderRemovalService $removal,
        OrderComponentSelection $selection,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($component->order_id === $order->id, 404);

        $components = $selection->resolve($request, $order, $component);

        foreach ($components as $row) {
            $this->guardEditable($request, $order, $row);
        }

        DB::transaction(function () use ($request, $order, $components, $removal): void {
            $this->reopenReconciledOrder($order, $removal);
            $this->distributeRefund(
                $components,
                round((float) $request->input('refund_amount'), 2),
                $request->string('refund_kind')->toString(),
            );
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
        OrderComponentSelection $selection,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($component->order_id === $order->id, 404);

        $components = $selection->resolve($request, $order, $component);

        foreach ($components as $row) {
            $this->guardEditable($request, $order, $row);
        }

        DB::transaction(function () use ($order, $components, $removal): void {
            $this->reopenReconciledOrder($order, $removal);

            foreach ($components as $row) {
                $row->update([
                    'refund_amount' => null,
                    'refund_kind' => null,
                ]);
            }
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Refund cleared.');
    }

    /**
     * @param  Collection<int, OrderComponent>  $components
     */
    protected function distributeRefund(Collection $components, float $refundAmount, string $kind): void
    {
        $remaining = (int) round($refundAmount * 100);
        $rows = $components->sortBy('id')->values();
        $lastIndex = $rows->count() - 1;

        foreach ($rows as $index => $row) {
            $cap = (int) round(((float) $row->amount) * 100);
            $share = $index === $lastIndex ? $remaining : min($remaining, max($cap, 0));
            $remaining -= $share;

            if ($share <= 0) {
                $row->update([
                    'refund_amount' => null,
                    'refund_kind' => null,
                    'is_user_modified' => true,
                ]);

                continue;
            }

            $row->update([
                'refund_amount' => number_format($share / 100, 2, '.', ''),
                'refund_kind' => $kind,
                'is_user_modified' => true,
            ]);
        }
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
