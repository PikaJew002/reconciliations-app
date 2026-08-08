<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Services\Orders\OrderBrowseService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request, OrderBrowseService $browse): Response
    {
        $data = $browse->index(
            $request->user()->id,
            $request->string('q')->toString() ?: null,
            $request->string('merchant')->toString() ?: 'walmart',
        );

        return Inertia::render('Orders/Index', $data);
    }
}
