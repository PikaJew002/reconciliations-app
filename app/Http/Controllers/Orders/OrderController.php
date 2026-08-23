<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Merchants\MerchantBrowseService;
use App\Services\Orders\OrderBrowseService;
use App\Services\Orders\OrderRemovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(
        Request $request,
        OrderBrowseService $orderBrowse,
        MerchantBrowseService $merchantBrowse,
    ): Response {
        $orders = $orderBrowse->index($request->user()->id);
        $merchants = $merchantBrowse->index(
            $request->user()->id,
            $request->string('q')->toString() ?: null,
        );

        return Inertia::render('Orders/Index', [
            ...$orders,
            ...$merchants,
        ]);
    }

    public function show(Request $request, string $merchant, OrderBrowseService $browse): Response
    {
        $data = $browse->show(
            $request->user()->id,
            $merchant,
            $request->string('q')->toString() ?: null,
        );

        return Inertia::render('Orders/Show', $data);
    }

    public function detail(
        Request $request,
        string $merchant,
        Order $order,
        OrderBrowseService $browse,
    ): Response {
        $data = $browse->detail(
            $request->user()->id,
            $merchant,
            $order->id,
        );

        return Inertia::render('Orders/Detail', $data);
    }

    public function destroy(
        Request $request,
        string $merchant,
        Order $order,
        OrderBrowseService $browse,
        OrderRemovalService $removal,
    ): RedirectResponse {
        $owned = $browse->findOwned($request->user()->id, $merchant, $order->id);
        $orderNumber = $owned->order_number;

        $removal->remove($owned);

        return redirect()
            ->route('orders.show', $merchant)
            ->with('success', "Order {$orderNumber} removed.");
    }
}
