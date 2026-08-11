<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\ResolveOrderPaymentsRequest;
use App\Models\Order;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class OrderPaymentResolutionController extends Controller
{
    public function store(
        ResolveOrderPaymentsRequest $request,
        Order $order,
        OrderPaymentResolutionService $resolution,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);

        try {
            $resolution->resolve($order, $request->input('payments', []));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return redirect()
                ->route('reconciliation.needs-review')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Multi-payment order reconciled.');
    }

    public function destroy(
        Request $request,
        Order $order,
        int $paymentIndex,
        OrderPaymentResolutionService $resolution,
    ): RedirectResponse {
        abort_unless($order->user_id === $request->user()->id, 403);

        try {
            $resolution->removePayment($order, $paymentIndex);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('reconciliation.needs-review')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Payment method removed.');
    }
}
