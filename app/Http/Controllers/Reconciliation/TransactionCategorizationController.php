<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyCategorizationRun;
use App\Models\BankTransaction;
use App\Models\CategorizationRun;
use App\Models\Category;
use App\Models\TransactionCategorizationRule;
use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class TransactionCategorizationController extends Controller
{
    public function store(
        Request $request,
        BankTransaction $transaction,
        TransactionCategorizationService $categorization,
    ): RedirectResponse {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'classification' => [
                'required',
                Rule::in([
                    BankTransaction::CLASSIFICATION_BILL,
                    BankTransaction::CLASSIFICATION_EXPENSE,
                    BankTransaction::CLASSIFICATION_INCOME,
                ]),
            ],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'match_mode' => ['required', 'string'],
            'normalized_pattern' => ['nullable', 'string', 'max:255'],
        ]);

        $isIncome = $validated['classification'] === BankTransaction::CLASSIFICATION_INCOME;
        $categoryId = $validated['category_id'] ?? null;

        if ($isIncome) {
            abort_unless((float) $transaction->amount > 0, 422, 'Only credits can be categorized as income.');
            abort_unless(
                in_array($validated['match_mode'], TransactionCategorizationRule::incomeAllMatchModes(), true),
                422,
                'Invalid match mode for income.',
            );
        } else {
            abort_unless((float) $transaction->amount < 0, 422, 'Only debit transactions can be categorized as bills or expenses.');
            abort_unless($categoryId !== null, 422, 'A category is required for bills and expenses.');
            abort_unless(
                in_array($validated['match_mode'], TransactionCategorizationRule::allMatchModes(), true),
                422,
                'Invalid match mode.',
            );
        }

        $category = null;

        if ($categoryId !== null) {
            $category = Category::query()->findOrFail($categoryId);
            abort_unless($category->user_id === $request->user()->id, 403);

            $expectedKind = match ($validated['classification']) {
                BankTransaction::CLASSIFICATION_BILL => Category::KIND_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE => Category::KIND_EXPENSE,
                BankTransaction::CLASSIFICATION_INCOME => Category::KIND_INCOME,
                default => null,
            };

            abort_unless($expectedKind !== null && $category->kind === $expectedKind, 422, 'Category kind must match classification.');
        } elseif (! $isIncome) {
            abort(422, 'A category is required for bills and expenses.');
        }

        $transaction->loadMissing('merchant');

        if (! $isIncome) {
            abort_unless(! $transaction->merchant?->supports_order_import, 422, 'Order-import merchant transactions wait for real orders.');

            if (
                in_array($validated['match_mode'], TransactionCategorizationRule::billOnlyMatchModes(), true)
                && $validated['classification'] !== BankTransaction::CLASSIFICATION_BILL
            ) {
                abort(422, 'This match mode is only available for bills.');
            }
        }

        try {
            $categorization->categorizeTransaction(
                $transaction,
                $category,
                $validated['classification'],
                $validated['match_mode'],
                $validated['normalized_pattern'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($validated['match_mode'] === TransactionCategorizationRule::MATCH_ONCE) {
            return redirect()
                ->back(fallback: route('reconciliation.unmatched-transactions'))
                ->with('success', 'Transaction categorized.');
        }

        $run = CategorizationRun::query()->create([
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'metadata' => [
                'source_transaction_id' => $transaction->id,
                'category_id' => $category?->id,
                'classification' => $validated['classification'],
                'match_mode' => $validated['match_mode'],
                'normalized_pattern' => $validated['normalized_pattern'] ?? null,
            ],
        ]);

        ApplyCategorizationRun::dispatch($run->id);

        return redirect()
            ->back(fallback: route('reconciliation.unmatched-transactions'))
            ->with('success', 'Transaction categorized. Applying rule to similar transactions…');
    }
}
