<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Services\Reconciliation\IncomeClassificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionClassificationController extends Controller
{
    public function confirmIncome(
        Request $request,
        BankTransaction $transaction,
        IncomeClassificationService $incomeClassification,
    ): RedirectResponse {
        abort_unless($transaction->user_id === $request->user()->id, 403);
        abort_unless((float) $transaction->amount > 0, 422, 'Only credits can be classified as income.');

        $incomeClassification->confirmIncome($transaction);

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Income confirmed. Similar credits will be classified automatically.');
    }

    public function rejectIncome(
        Request $request,
        BankTransaction $transaction,
        IncomeClassificationService $incomeClassification,
    ): RedirectResponse {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $incomeClassification->rejectIncome($transaction);

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Income suggestion dismissed.');
    }
}
