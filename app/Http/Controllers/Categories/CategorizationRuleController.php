<?php

namespace App\Http\Controllers\Categories;

use App\Http\Controllers\Controller;
use App\Models\TransactionCategorizationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategorizationRuleController extends Controller
{
    public function update(Request $request, TransactionCategorizationRule $rule): RedirectResponse
    {
        $this->ensureOwned($request, $rule);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'match_mode' => ['sometimes', Rule::in(TransactionCategorizationRule::persistableMatchModes())],
        ]);

        $rule->update($validated);

        return redirect()
            ->route('rules.index', ['tab' => 'expenses'])
            ->with('success', 'Rule updated.');
    }

    public function destroy(Request $request, TransactionCategorizationRule $rule): RedirectResponse
    {
        $this->ensureOwned($request, $rule);

        $rule->delete();

        return redirect()
            ->route('rules.index', ['tab' => 'expenses'])
            ->with('success', 'Rule deleted.');
    }

    private function ensureOwned(Request $request, TransactionCategorizationRule $rule): void
    {
        if ($rule->user_id !== $request->user()->id) {
            throw new NotFoundHttpException();
        }
    }
}
