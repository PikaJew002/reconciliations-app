<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyIncomeClassificationRun;
use App\Models\BankTransaction;
use App\Models\CategorizationRun;
use App\Models\TransactionClassificationRule;
use App\Services\Reconciliation\IncomeClassificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionClassificationController extends Controller
{
    public function confirmIncome(
        Request $request,
        BankTransaction $transaction,
        IncomeClassificationService $incomeClassification,
    ): RedirectResponse {
        abort_unless($transaction->user_id === $request->user()->id, 403);
        abort_unless((float) $transaction->amount > 0, 422, 'Only credits can be classified as income.');

        $validated = $request->validate([
            'match_mode' => ['required', Rule::in(TransactionClassificationRule::allMatchModes())],
        ]);

        $incomeClassification->confirmIncome($transaction, $validated['match_mode']);

        if ($validated['match_mode'] === TransactionClassificationRule::MATCH_ONCE) {
            return redirect()
                ->route('reconciliation.needs-review')
                ->with('success', 'Income confirmed.');
        }

        $run = CategorizationRun::query()->create([
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'metadata' => [
                'source_transaction_id' => $transaction->id,
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'match_mode' => $validated['match_mode'],
            ],
        ]);

        ApplyIncomeClassificationRun::dispatch($run->id);

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Income confirmed. Applying rule to similar credits…');
    }

    public function rejectIncome(
        Request $request,
        BankTransaction $transaction,
        IncomeClassificationService $incomeClassification,
    ): RedirectResponse {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $incomeClassification->rejectIncome($transaction);

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Income suggestion dismissed.');
    }
}
