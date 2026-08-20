<?php

namespace App\Http\Controllers\PendingSpends;

use App\Http\Controllers\Controller;
use App\Http\Requests\PendingSpends\StorePendingSpendRequest;
use App\Services\Reconciliation\PendingSpendService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class PendingSpendController extends Controller
{
    public function store(StorePendingSpendRequest $request, PendingSpendService $service): JsonResponse
    {
        try {
            $pendingSpend = $service->create($request->user(), $request->validated());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'id' => $pendingSpend->id,
            'account_id' => $pendingSpend->account_id,
            'source' => $pendingSpend->source,
            'spent_at' => $pendingSpend->spent_at->toIso8601String(),
            'amount' => $pendingSpend->amount,
            'merchant_id' => $pendingSpend->merchant_id,
            'category_id' => $pendingSpend->category_id,
            'status' => $pendingSpend->status,
            'notes' => $pendingSpend->notes,
        ], 201);
    }
}
