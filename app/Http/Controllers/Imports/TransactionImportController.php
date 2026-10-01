<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreTransactionImportRequest;
use App\Services\Imports\TillerImportStarter;
use Illuminate\Http\JsonResponse;

class TransactionImportController extends Controller
{
    public function store(StoreTransactionImportRequest $request, TillerImportStarter $starter): JsonResponse
    {
        $transactions = $request->validated('transactions');

        $starter->start($request->user(), $transactions);

        return response()->json([
            'received' => count($transactions),
        ]);
    }
}
