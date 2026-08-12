<?php

namespace App\Http\Controllers\Budgets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\UpdateBudgetRequest;
use App\Services\Budgets\BudgetProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request, BudgetProgressService $budgetProgress): Response
    {
        $budgetYearId = $request->filled('budget_year_id')
            ? $request->integer('budget_year_id')
            : null;

        return Inertia::render(
            'Budgets/Index',
            $budgetProgress->planForUser($request->user()->id, $budgetYearId),
        );
    }

    public function update(
        UpdateBudgetRequest $request,
        BudgetProgressService $budgetProgress,
    ): RedirectResponse {
        $budgetYearId = (int) $request->validated('budget_year_id');

        $budgetProgress->syncLimits(
            $request->user()->id,
            $budgetYearId,
            $request->limitsByCategoryId(),
        );

        return redirect()
            ->route('budgets.index', ['budget_year_id' => $budgetYearId])
            ->with('success', 'Budgets saved.');
    }
}
