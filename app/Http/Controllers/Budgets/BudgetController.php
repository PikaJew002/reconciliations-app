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
        return Inertia::render(
            'Budgets/Index',
            $budgetProgress->planForUser($request->user()->id),
        );
    }

    public function update(
        UpdateBudgetRequest $request,
        BudgetProgressService $budgetProgress,
    ): RedirectResponse {
        $budgetProgress->syncLimits(
            $request->user()->id,
            $request->limitsByCategoryId(),
        );

        return redirect()
            ->route('budgets.index')
            ->with('success', 'Budgets saved.');
    }
}
