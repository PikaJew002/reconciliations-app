<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\TransactionCategorizationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IncomeClassificationRuleController extends Controller
{
    public function update(Request $request, TransactionCategorizationRule $rule): RedirectResponse
    {
        $this->ensureOwnedIncomeRule($request, $rule);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'match_mode' => ['sometimes', Rule::in(TransactionCategorizationRule::incomeMatchModes())],
        ]);

        $rule->update($validated);

        return redirect()
            ->route('rules.index', ['tab' => 'income'])
            ->with('success', 'Income rule updated.');
    }

    public function destroy(Request $request, TransactionCategorizationRule $rule): RedirectResponse
    {
        $this->ensureOwnedIncomeRule($request, $rule);

        $rule->delete();

        return redirect()
            ->route('rules.index', ['tab' => 'income'])
            ->with('success', 'Income rule deleted.');
    }

    public function destroyDescriptionOnly(Request $request): RedirectResponse
    {
        $deleted = TransactionCategorizationRule::query()
            ->where('user_id', $request->user()->id)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('match_mode', TransactionCategorizationRule::MATCH_DESCRIPTION)
            ->delete();

        return redirect()
            ->route('rules.index', ['tab' => 'income'])
            ->with('success', "Deleted {$deleted} description-only income rule".($deleted === 1 ? '' : 's').'.');
    }

    private function ensureOwnedIncomeRule(Request $request, TransactionCategorizationRule $rule): void
    {
        if (
            $rule->user_id !== $request->user()->id
            || $rule->classification !== BankTransaction::CLASSIFICATION_INCOME
        ) {
            throw new NotFoundHttpException();
        }
    }
}
