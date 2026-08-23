<?php

namespace App\Http\Controllers\PendingSpends;

use App\Http\Controllers\Controller;
use App\Http\Requests\PendingSpends\StorePendingSpendRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Services\Reconciliation\PendingSpendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PendingSpendController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('kind', Category::KIND_EXPENSE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Category $category): array => [
                $category->name => $category->id,
            ]);

        $merchants = Merchant::query()
            ->where('user_id', $userId)
            ->where('supports_order_import', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Merchant $merchant): array => [
                $merchant->name => $merchant->id,
            ]);

        $accounts = Account::query()
            ->where('user_id', $userId)
            ->tracked()
            ->whereIn('account_type', [Account::CHECKING, Account::CREDIT_CARD])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Account $account): array => [
                $account->name => $account->id,
            ]);

        return response()->json([
            'categories' => (object) $categories->all(),
            'merchants' => (object) $merchants->all(),
            'accounts' => (object) $accounts->all(),
        ]);
    }

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
