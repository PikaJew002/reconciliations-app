<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\OrderComponent;
use App\Models\PendingSpend;
use App\Models\TransactionCategorizationRule;
use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReviewCategorizationController extends Controller
{
    public function store(
        Request $request,
        TransactionCategorizationService $categorization,
    ): RedirectResponse {
        $request->merge([
            'category_id' => $request->filled('category_id')
                ? $request->input('category_id')
                : null,
        ]);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['bank', 'pending', 'order_component'])],
            'id' => ['required', 'integer'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'week' => ['nullable', 'date'],
            'item' => ['nullable', 'string'],
            'act' => ['nullable', Rule::in(['open', 'walk', 'close'])],
            'pass' => ['nullable', Rule::in(['default', 'all'])],
        ]);

        $userId = $request->user()->id;
        $category = $this->resolveCategory($userId, $validated['category_id'] ?? null);

        match ($validated['type']) {
            'bank' => $this->categorizeBank(
                $userId,
                (int) $validated['id'],
                $category,
                $categorization,
            ),
            'pending' => $this->categorizePending($userId, (int) $validated['id'], $category),
            'order_component' => $this->categorizeOrderComponent($userId, (int) $validated['id'], $category),
        };

        return redirect()->route('review.sunday', [
            'week' => $validated['week'] ?? null,
            'item' => $validated['item'] ?? null,
            'act' => $validated['act'] ?? 'walk',
            'pass' => $validated['pass'] ?? 'default',
        ]);
    }

    protected function resolveCategory(int $userId, ?int $categoryId): ?Category
    {
        if ($categoryId === null) {
            return null;
        }

        $category = Category::query()->findOrFail($categoryId);
        abort_unless($category->user_id === $userId, 403);
        abort_unless(in_array($category->kind, [Category::KIND_BILL, Category::KIND_EXPENSE], true), 422);

        return $category;
    }

    protected function categorizeBank(
        int $userId,
        int $transactionId,
        ?Category $category,
        TransactionCategorizationService $categorization,
    ): void {
        $transaction = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereKey($transactionId)
            ->with('merchant')
            ->firstOrFail();

        if ($category === null) {
            $transaction->update(['category_id' => null]);

            return;
        }

        $classification = $category->kind === Category::KIND_BILL
            ? BankTransaction::CLASSIFICATION_BILL
            : BankTransaction::CLASSIFICATION_EXPENSE;

        try {
            $categorization->categorizeTransaction(
                $transaction,
                $category,
                $classification,
                TransactionCategorizationRule::MATCH_ONCE,
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    protected function categorizePending(int $userId, int $pendingId, ?Category $category): void
    {
        $pending = PendingSpend::query()
            ->where('user_id', $userId)
            ->whereKey($pendingId)
            ->firstOrFail();

        $classification = $category === null
            ? $pending->classification
            : ($category->kind === Category::KIND_BILL
                ? BankTransaction::CLASSIFICATION_BILL
                : BankTransaction::CLASSIFICATION_EXPENSE);

        $pending->update([
            'category_id' => $category?->id,
            'classification' => $classification,
        ]);
    }

    protected function categorizeOrderComponent(int $userId, int $componentId, ?Category $category): void
    {
        $component = OrderComponent::query()
            ->whereKey($componentId)
            ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
            ->firstOrFail();

        abort_unless(
            $category === null || $category->kind === Category::KIND_EXPENSE,
            422,
            'Order lines can only use expense categories.',
        );

        $component->update([
            'category_id' => $category?->id,
            'category_confidence' => $category !== null ? 100 : null,
            'is_user_modified' => true,
        ]);

        if ($category === null || ! $component->isProduct()) {
            return;
        }

        $component->loadMissing('orderItem.product');
        $product = $component->orderItem?->product;

        if ($product && $product->user_id === $userId) {
            $product->update([
                'category_id' => $category->id,
                'category_confidence' => 100,
                'is_user_modified' => true,
            ]);
        }
    }
}
