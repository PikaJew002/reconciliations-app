<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Services\Orders\OrderCategorizationBrowseService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderCategorizationController extends Controller
{
    public function index(Request $request, OrderCategorizationBrowseService $browse): Response
    {
        $data = $browse->index(
            $request->user()->id,
            $request->string('q')->toString() ?: null,
        );

        return Inertia::render('Orders/Categorize', $data);
    }
}
