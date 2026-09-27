<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\UpdateOrderTotalRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

class OrderTotalController extends Controller
{
    public function update(UpdateOrderTotalRequest $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_if($order->status === 'reconciled', 422, 'Reconciled orders cannot be edited.');
        abort_if(
            $order->components()->whereHas('allocations')->exists(),
            422,
            'Allocated orders cannot be edited.',
        );

        $order->update([
            'total' => round((float) $request->input('total'), 2),
        ]);

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Bank total updated.');
    }
}
