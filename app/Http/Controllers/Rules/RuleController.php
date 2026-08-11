<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\TransactionCategorizationRule;
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

        $incomeRules = TransactionCategorizationRule::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->with(['category:id,name,kind'])
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
            ]);

        $expenseRules = TransactionCategorizationRule::query()
            ->where('user_id', $userId)
            ->whereIn('classification', [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ])
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
            'incomeMatchModes' => TransactionCategorizationRule::incomeMatchModes(),
            'expenseMatchModes' => TransactionCategorizationRule::persistableMatchModes(),
        ]);
    }
}
