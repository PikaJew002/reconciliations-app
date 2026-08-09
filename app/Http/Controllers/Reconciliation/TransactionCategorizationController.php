<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TransactionCategorizationRule;
use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
                ]),
            ],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'match_mode' => ['required', Rule::in(TransactionCategorizationRule::allMatchModes())],
        ]);

        $category = Category::query()->findOrFail($validated['category_id']);

        abort_unless($category->user_id === $request->user()->id, 403);

        $transaction->loadMissing('merchant');

        abort_unless((float) $transaction->amount < 0, 422, 'Only debit transactions can be categorized.');
        abort_unless(! $transaction->merchant?->supports_order_import, 422, 'Order-import merchant transactions wait for real orders.');

        $expectedKind = $validated['classification'] === BankTransaction::CLASSIFICATION_BILL
            ? Category::KIND_BILL
            : Category::KIND_EXPENSE;

        abort_unless($category->kind === $expectedKind, 422, 'Category kind must match classification.');

        $categorization->categorizeTransaction(
            $transaction,
            $category,
            $validated['classification'],
            $validated['match_mode'],
        );

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Transaction categorized.');
    }
}
