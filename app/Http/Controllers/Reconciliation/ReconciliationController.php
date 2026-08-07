<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Services\Reconciliation\ReconciliationReviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function index(Request $request, ReconciliationReviewService $review): Response
    {
        $data = $review->forUser($request->user()->id);

        return Inertia::render('Reconciliation/Index', $data);
    }
}
