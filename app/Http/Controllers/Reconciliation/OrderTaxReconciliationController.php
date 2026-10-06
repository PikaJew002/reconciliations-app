<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\StoreOrderTaxReconciliationRequest;
use App\Models\Order;
use App\Services\Reconciliation\OrderTaxReconciler;
use Illuminate\Http\RedirectResponse;

class OrderTaxReconciliationController extends Controller
{
    public function store(
        StoreOrderTaxReconciliationRequest $request,
        Order $order,
        OrderTaxReconciler $reconciler,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);

        $reconciler->apply(
            $order,
            $request->user(),
            $request->string('rate')->toString(),
            $request->input('component_ids', []),
        );

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Sales tax assigned to the taxable lines.');
    }
}
