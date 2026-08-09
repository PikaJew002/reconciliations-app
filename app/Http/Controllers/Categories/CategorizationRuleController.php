<?php

namespace App\Http\Controllers\Categories;

use App\Http\Controllers\Controller;
use App\Models\TransactionCategorizationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategorizationRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $rules = TransactionCategorizationRule::query()
            ->where('user_id', $request->user()->id)
            ->with(['category:id,name,kind', 'merchant:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (TransactionCategorizationRule $rule) => [
                'id' => $rule->id,
                'classification' => $rule->classification,
                'match_mode' => $rule->match_mode,
                'normalized_pattern' => $rule->normalized_pattern,
                'amount' => $rule->amount,
                'is_active' => $rule->is_active,
                'category' => $rule->category ? [
                    'id' => $rule->category->id,
                    'name' => $rule->category->name,
                    'kind' => $rule->category->kind,
                ] : null,
                'merchant' => $rule->merchant ? [
                    'id' => $rule->merchant->id,
                    'name' => $rule->merchant->name,
                ] : null,
            ]);

        return Inertia::render('CategorizationRules/Index', [
            'rules' => $rules,
            'matchModes' => TransactionCategorizationRule::persistableMatchModes(),
        ]);
    }

    public function update(Request $request, TransactionCategorizationRule $rule): RedirectResponse
    {
        $this->ensureOwned($request, $rule);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'match_mode' => ['sometimes', Rule::in(TransactionCategorizationRule::persistableMatchModes())],
        ]);

        $rule->update($validated);

        return redirect()
            ->route('categorization-rules.index')
            ->with('success', 'Rule updated.');
    }

    public function destroy(Request $request, TransactionCategorizationRule $rule): RedirectResponse
    {
        $this->ensureOwned($request, $rule);

        $rule->delete();

        return redirect()
            ->route('categorization-rules.index')
            ->with('success', 'Rule deleted.');
    }

    private function ensureOwned(Request $request, TransactionCategorizationRule $rule): void
    {
        if ($rule->user_id !== $request->user()->id) {
            throw new NotFoundHttpException();
        }
    }
}
