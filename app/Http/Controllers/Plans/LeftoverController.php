<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Services\Plans\PaycheckLeftoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeftoverController extends Controller
{
    public function index(Request $request, PaycheckLeftoverService $leftover): JsonResponse
    {
        return response()->json([
            'windows' => $leftover->windows($request->user()->id),
        ]);
    }

    public function current(Request $request, PaycheckLeftoverService $leftover): JsonResponse
    {
        return response()->json([
            'leftover' => $leftover->current($request->user()->id),
        ]);
    }
}
