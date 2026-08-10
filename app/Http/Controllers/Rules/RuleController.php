<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\TransactionCategorizationRule;
use App\Models\TransactionClassificationRule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RuleController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->query('tab', 'income');

        if (! in_array($tab, ['income', 'expenses'], true)) {
            $tab = 'income';
        }

        $userId = $request->user()->id;

        $incomeRules = TransactionClassificationRule::query()
            ->where('user_id', $userId)
            ->where('classification', TransactionClassificationRule::CLASSIFICATION_INCOME)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (TransactionClassificationRule $rule) => [
                'id' => $rule->id,
                'classification' => $rule->classification,
                'match_mode' => $rule->match_mode,
                'normalized_pattern' => $rule->normalized_pattern,
                'amount' => $rule->amount !== null ? (float) $rule->amount : null,
                'origin' => $rule->origin,
                'is_active' => $rule->is_active,
            ]);

        $expenseRules = TransactionCategorizationRule::query()
            ->where('user_id', $userId)
            ->with(['category:id,name,kind', 'merchant:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (TransactionCategorizationRule $rule) => [
                'id' => $rule->id,
                'classification' => $rule->classification,
                'match_mode' => $rule->match_mode,
                'normalized_pattern' => $rule->normalized_pattern,
                'amount' => $rule->amount !== null ? (float) $rule->amount : null,
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

        return Inertia::render('Rules/Index', [
            'tab' => $tab,
            'incomeRules' => $incomeRules,
            'expenseRules' => $expenseRules,
            'incomeMatchModes' => TransactionClassificationRule::persistableMatchModes(),
            'expenseMatchModes' => TransactionCategorizationRule::persistableMatchModes(),
        ]);
    }
}
