<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\UpdateOrderTotalRequest;
use App\Models\Order;
use App\Services\Orders\OrderRemovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrderTotalController extends Controller
{
    public function update(
        UpdateOrderTotalRequest $request,
        Order $order,
        OrderRemovalService $removal,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);

        if ($order->status !== 'reconciled') {
            abort_if(
                $order->components()->whereHas('allocations')->exists(),
                422,
                'Allocated orders cannot be edited.',
            );
        }

        DB::transaction(function () use ($request, $order, $removal): void {
            if ($order->status === 'reconciled') {
                $removal->unwindAllocations($order);
            }

            $order->update([
                'total' => round((float) $request->input('total'), 2),
            ]);
        });

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Bank total updated.');
    }
}
