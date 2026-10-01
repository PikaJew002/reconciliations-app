<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreTransactionImportRequest;
use Illuminate\Http\JsonResponse;

class TransactionImportController extends Controller
{
    public function store(StoreTransactionImportRequest $request): JsonResponse
    {
        return response()->json([
            'received' => count($request->validated('transactions')),
        ]);
    }
}
