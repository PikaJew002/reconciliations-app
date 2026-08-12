<?php

namespace App\Http\Controllers\Budgets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\StoreBudgetYearRequest;
use App\Http\Requests\Budgets\UpdateBudgetYearRequest;
use App\Models\BudgetYear;
use App\Services\Budgets\BudgetProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BudgetYearController extends Controller
{
    public function store(
        StoreBudgetYearRequest $request,
        BudgetProgressService $budgetProgress,
    ): RedirectResponse {
        $validated = $request->validated();

        $year = $budgetProgress->createYear(
            $request->user()->id,
            $validated['starts_on'],
            $validated['color'],
            $validated['label'] ?? null,
            (bool) ($validated['make_current'] ?? false),
        );

        return redirect()
            ->route('budgets.index', ['budget_year_id' => $year->id])
            ->with('success', 'Budget year created.');
    }

    public function update(
        UpdateBudgetYearRequest $request,
        BudgetYear $budgetYear,
        BudgetProgressService $budgetProgress,
    ): RedirectResponse {
        $this->ensureOwned($request, $budgetYear);

        $year = $budgetProgress->updateYear(
            $request->user()->id,
            $budgetYear->id,
            $request->validated(),
        );

        return redirect()
            ->route('budgets.index', ['budget_year_id' => $year->id])
            ->with('success', 'Budget year updated.');
    }

    public function makeCurrent(
        Request $request,
        BudgetYear $budgetYear,
        BudgetProgressService $budgetProgress,
    ): RedirectResponse {
        $this->ensureOwned($request, $budgetYear);

        $year = $budgetProgress->setCurrentYear($request->user()->id, $budgetYear->id);

        $redirect = $request->string('redirect')->toString();

        if ($redirect === 'dashboard') {
            return redirect()
                ->route('dashboard', ['budget_year_id' => $year->id])
                ->with('success', 'Current budget year updated.');
        }

        return redirect()
            ->route('budgets.index', ['budget_year_id' => $year->id])
            ->with('success', 'Current budget year updated.');
    }

    private function ensureOwned(Request $request, BudgetYear $budgetYear): void
    {
        if ($budgetYear->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }
    }
}
