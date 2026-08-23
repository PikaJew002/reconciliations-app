<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Services\Plans\PaycheckLeftoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeftoverController extends Controller
{
    public function current(Request $request, PaycheckLeftoverService $leftover): JsonResponse
    {
        $window = $leftover->current($request->user()->id);

        return response()->json([
            'remaining' => $window['remaining'] ?? null,
            'days_remaining' => $window['days_remaining'] ?? null,
        ]);
    }
}
