<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\TransactionClassificationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IncomeClassificationRuleController extends Controller
{
    public function update(Request $request, TransactionClassificationRule $rule): RedirectResponse
    {
        $this->ensureOwnedIncomeRule($request, $rule);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $rule->update($validated);

        return redirect()
            ->route('rules.index', ['tab' => 'income'])
            ->with('success', 'Income rule updated.');
    }

    public function destroy(Request $request, TransactionClassificationRule $rule): RedirectResponse
    {
        $this->ensureOwnedIncomeRule($request, $rule);

        $rule->delete();

        return redirect()
            ->route('rules.index', ['tab' => 'income'])
            ->with('success', 'Income rule deleted.');
    }

    public function destroyDescriptionOnly(Request $request): RedirectResponse
    {
        $deleted = TransactionClassificationRule::query()
            ->where('user_id', $request->user()->id)
            ->where('classification', TransactionClassificationRule::CLASSIFICATION_INCOME)
            ->where('match_mode', TransactionClassificationRule::MATCH_DESCRIPTION)
            ->where('origin', TransactionClassificationRule::ORIGIN_USER_CONFIRMED)
            ->delete();

        return redirect()
            ->route('rules.index', ['tab' => 'income'])
            ->with('success', $deleted === 1
                ? 'Deleted 1 description-only income rule.'
                : "Deleted {$deleted} description-only income rules.");
    }

    private function ensureOwnedIncomeRule(Request $request, TransactionClassificationRule $rule): void
    {
        if (
            $rule->user_id !== $request->user()->id
            || $rule->classification !== TransactionClassificationRule::CLASSIFICATION_INCOME
        ) {
            throw new NotFoundHttpException();
        }
    }
}
