<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Budgets\BudgetProgressService;
use App\Services\Plans\PaycheckBillAssignmentService;
use App\Services\Plans\PaycheckLeftoverService;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Reporting\CategorySpendQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        CategorySpendQuery $categorySpendQuery,
        BudgetProgressService $budgetProgress,
        PlannedOccurrenceGenerator $generator,
        PaycheckBillAssignmentService $assignments,
        PaycheckLeftoverService $leftover,
    ): Response {
        $userId = $request->user()->id;

        $view = $request->string('view')->toString();

        if (! in_array($view, ['month', 'ytm'], true)) {
            $view = 'month';
        }

        $month = $request->string('month')->toString();
        $month = $month !== '' ? $month : null;

        $budgetYearId = $request->filled('budget_year_id')
            ? $request->integer('budget_year_id')
            : null;

        $selectedYear = $budgetProgress->resolveYear($userId, $budgetYearId);
        $resolved = $budgetProgress->resolvePeriod($userId, $view, $month, $selectedYear);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $progress = $budgetProgress->build($userId, $view, $month, $selectedYear?->id);
        $monthsElapsed = $progress['period']['months_elapsed'];

        $generator->ensureForUser($userId);
        $paycheckPlans = $view === 'month'
            ? $assignments->monthCards($userId, $from)
            : [
                'paychecks' => [],
                'income' => 0.0,
                'bills' => 0.0,
                'leftover' => 0.0,
            ];

        $spendTotals = $categorySpendQuery->categoryTotalsForUser($userId, $from, $to);

        foreach ($categorySpendQuery->orderComponentCategoryTotalsForUser($userId, $from, $to) as $categoryId => $amount) {
            $spendTotals[$categoryId] = round(($spendTotals[$categoryId] ?? 0) + $amount, 2);
        }

        $incomeTotals = $categorySpendQuery->incomeCategoryTotalsForUser($userId, $from, $to);

        $uncategorizedBill = $categorySpendQuery->uncategorizedBillSpendForUser($userId, $from, $to);
        $uncategorizedExpense = round(
            $categorySpendQuery->uncategorizedExpenseSpendForUser($userId, $from, $to)
            + $categorySpendQuery->orderComponentUncategorizedSpendForUser($userId, $from, $to),
            2,
        );
        $uncategorizedIncome = $categorySpendQuery->uncategorizedIncomeForUser($userId, $from, $to);

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        $budgetByCategoryId = collect($progress['categories'])->keyBy('id');

        $billCategories = $this->withBudgetProgress(
            $this->categoryCards(
                $categories->where('kind', Category::KIND_BILL),
                $spendTotals,
            ),
            $budgetByCategoryId,
        );
        $expenseCategories = $this->withBudgetProgress(
            $this->categoryCards(
                $categories->where('kind', Category::KIND_EXPENSE),
                $spendTotals,
            ),
            $budgetByCategoryId,
        );
        $incomeCategories = $this->withBudgetProgress(
            $this->categoryCards(
                $categories->where('kind', Category::KIND_INCOME),
                $incomeTotals,
            ),
            $budgetByCategoryId,
        );

        $billsAmount = round(
            (float) collect($billCategories)->sum('amount') + $uncategorizedBill,
            2,
        );
        $expensesAmount = round(
            (float) collect($expenseCategories)->sum('amount') + $uncategorizedExpense,
            2,
        );
        $categorizedIncomeAmount = round((float) collect($incomeCategories)->sum('amount'), 2);

        $billCategories = $this->withKindPercents($billCategories, $billsAmount);
        $expenseCategories = $this->withKindPercents($expenseCategories, $expensesAmount);
        $incomeCategories = $this->withKindPercents(
            $incomeCategories,
            $categorizedIncomeAmount + $uncategorizedIncome,
        );

        $totalIncome = round($categorizedIncomeAmount + $uncategorizedIncome, 2);
        $totalSpend = round($billsAmount + $expensesAmount, 2);

        return Inertia::render('Dashboard/Index', [
            'view' => $progress['view'],
            'month' => $progress['month'],
            'budget_year' => $progress['budget_year'],
            'budget_years' => $progress['budget_years'],
            'period' => $progress['period'],
            'total_income' => $totalIncome,
            'total_spend' => $totalSpend,
            'summary' => $progress['summary'],
            'sections' => [
                'income' => [
                    'amount' => $totalIncome,
                    'categories' => $incomeCategories,
                    'uncategorized' => $this->uncategorizedPayload($uncategorizedIncome, $totalIncome),
                ],
                'spending' => [
                    'amount' => $totalSpend,
                    'bills' => [
                        'amount' => $billsAmount,
                        'categories' => $billCategories,
                        'uncategorized' => $this->uncategorizedPayload($uncategorizedBill, $billsAmount),
                    ],
                    'expenses' => [
                        'amount' => $expensesAmount,
                        'budget_allowed' => $progress['summary']['budget_allowed'],
                        'vs_budget_difference' => $progress['summary']['vs_budget_difference'],
                        'vs_leftover_difference' => $progress['summary']['vs_leftover_difference'],
                        'categories' => $expenseCategories,
                        'uncategorized' => $this->uncategorizedPayload(
                            $uncategorizedExpense,
                            $expensesAmount,
                        ),
                    ],
                ],
            ],
            'months_elapsed' => $monthsElapsed,
            'paycheck_plans' => $paycheckPlans,
            'paycheck_leftover' => $leftover->current($userId),
        ]);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  array<int, float>  $totals
     * @return list<array{id: int, name: string, kind: string, color: ?string, amount: float}>
     */
    protected function categoryCards($categories, array $totals): array
    {
        return $categories
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'color' => $category->color,
                'amount' => round((float) ($totals[$category->id] ?? 0), 2),
            ])
            ->filter(fn (array $category): bool => $category['amount'] > 0)
            ->sortBy([
                ['amount', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, name: string, kind: string, color: ?string, amount: float}>  $categories
     * @param  Collection<int, array<string, mixed>>  $budgetByCategoryId
     * @return list<array<string, mixed>>
     */
    protected function withBudgetProgress($categories, $budgetByCategoryId): array
    {
        return array_map(function (array $category) use ($budgetByCategoryId): array {
            $budget = $budgetByCategoryId->get($category['id']);

            $category['monthly_budget'] = $budget['monthly_budget'] ?? null;
            $category['budget_allowed'] = $budget['budget_allowed'] ?? null;
            $category['vs_budget_difference'] = $budget['vs_budget_difference'] ?? null;

            return $category;
        }, $categories);
    }

    /**
     * @param  list<array{id: int, name: string, kind: string, color: ?string, amount: float}>  $categories
     * @return list<array{id: int, name: string, kind: string, color: ?string, amount: float, percent: ?float}>
     */
    protected function withKindPercents(array $categories, float $kindTotal): array
    {
        return array_map(function (array $category) use ($kindTotal): array {
            $category['percent'] = $this->percentOf($category['amount'], $kindTotal);

            return $category;
        }, $categories);
    }

    /**
     * @return array{amount: float, percent: ?float}|null
     */
    protected function uncategorizedPayload(float $amount, float $sectionTotal): ?array
    {
        if ($amount <= 0) {
            return null;
        }

        return [
            'amount' => $amount,
            'percent' => $this->percentOf($amount, $sectionTotal),
        ];
    }

    protected function percentOf(float $part, float $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round(($part / $whole) * 100, 1);
    }
}
