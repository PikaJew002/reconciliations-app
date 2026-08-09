<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Services\Merchants\MerchantBrowseService;
use App\Services\Orders\OrderBrowseService;
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
}
