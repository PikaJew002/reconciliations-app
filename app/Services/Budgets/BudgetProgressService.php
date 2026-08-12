<?php

namespace App\Services\Budgets;

use App\Models\BudgetCategoryLimit;
use App\Models\Category;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BudgetProgressService
{
    public function __construct(
        protected CategorySpendQuery $categorySpendQuery,
    ) {}

    /**
     * @return array{
     *     view: string,
     *     month: string,
     *     period: array{from: string, to: string, label: string, months_elapsed: int},
     *     summary: array{
     *         income: float,
     *         bills: float,
     *         leftover_income: float,
     *         expenses: float,
     *         budget_allowed: float,
     *         vs_budget_difference: float,
     *         vs_leftover_difference: float
     *     },
     *     categories: list<array{
     *         id: int,
     *         name: string,
     *         color: ?string,
     *         monthly_budget: ?float,
     *         budget_allowed: ?float,
     *         spend: float,
     *         vs_budget_difference: ?float,
     *         leftover_income: float,
     *         vs_leftover_difference: float
     *     }>,
     *     uncategorized_expense: array{spend: float, leftover_income: float, vs_leftover_difference: float}
     * }
     */
    public function build(int $userId, string $view, ?string $month = null): array
    {
        if (! in_array($view, ['month', 'ytm'], true)) {
            throw new InvalidArgumentException('View must be month or ytm.');
        }

        $resolved = $this->resolvePeriod($view, $month);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $monthsElapsed = $resolved['months_elapsed'];

        $spendTotals = $this->categorySpendQuery->categoryTotalsForUser($userId, $from, $to);

        foreach ($this->categorySpendQuery->orderComponentCategoryTotalsForUser($userId, $from, $to) as $categoryId => $amount) {
            $spendTotals[$categoryId] = round(($spendTotals[$categoryId] ?? 0) + $amount, 2);
        }

        $uncategorizedExpense = round(
            $this->categorySpendQuery->uncategorizedExpenseSpendForUser($userId, $from, $to)
            + $this->categorySpendQuery->orderComponentUncategorizedSpendForUser($userId, $from, $to),
            2,
        );

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        $billCategories = $categories->where('kind', Category::KIND_BILL);
        $expenseCategories = $categories->where('kind', Category::KIND_EXPENSE);

        $billsAmount = round(
            (float) $billCategories->sum(fn (Category $category) => (float) ($spendTotals[$category->id] ?? 0))
            + $this->categorySpendQuery->uncategorizedBillSpendForUser($userId, $from, $to),
            2,
        );

        $income = $this->categorySpendQuery->incomeTotalForUser($userId, $from, $to);
        $leftoverIncome = round($income - $billsAmount, 2);

        /** @var Collection<int, BudgetCategoryLimit> $limits */
        $limits = BudgetCategoryLimit::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('category_id');

        $categoryRows = [];
        $totalBudgetAllowed = 0.0;
        $categorizedExpenseSpend = 0.0;

        foreach ($expenseCategories as $category) {
            $spend = round((float) ($spendTotals[$category->id] ?? 0), 2);
            $categorizedExpenseSpend = round($categorizedExpenseSpend + $spend, 2);

            $limit = $limits->get($category->id);
            $monthlyBudget = $limit !== null ? round((float) $limit->amount, 2) : null;
            $budgetAllowed = $monthlyBudget !== null
                ? round($monthlyBudget * $monthsElapsed, 2)
                : null;

            if ($budgetAllowed !== null) {
                $totalBudgetAllowed = round($totalBudgetAllowed + $budgetAllowed, 2);
            }

            $categoryRows[] = [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
                'monthly_budget' => $monthlyBudget,
                'budget_allowed' => $budgetAllowed,
                'spend' => $spend,
                'vs_budget_difference' => $budgetAllowed !== null
                    ? round($budgetAllowed - $spend, 2)
                    : null,
                'leftover_income' => $leftoverIncome,
                'vs_leftover_difference' => round($leftoverIncome - $spend, 2),
            ];
        }

        $totalExpenses = round($categorizedExpenseSpend + $uncategorizedExpense, 2);

        return [
            'view' => $view,
            'month' => $resolved['month'],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->copy()->subDay()->toDateString(),
                'label' => $resolved['label'],
                'months_elapsed' => $monthsElapsed,
            ],
            'summary' => [
                'income' => $income,
                'bills' => $billsAmount,
                'leftover_income' => $leftoverIncome,
                'expenses' => $totalExpenses,
                'budget_allowed' => $totalBudgetAllowed,
                'vs_budget_difference' => round($totalBudgetAllowed - $totalExpenses, 2),
                'vs_leftover_difference' => round($leftoverIncome - $totalExpenses, 2),
            ],
            'categories' => $categoryRows,
            'uncategorized_expense' => [
                'spend' => $uncategorizedExpense,
                'leftover_income' => $leftoverIncome,
                'vs_leftover_difference' => round($leftoverIncome - $uncategorizedExpense, 2),
            ],
        ];
    }

    /**
     * Year-plan setup payload: all expense categories with monthly budgets.
     *
     * @return array{
     *     year: int,
     *     total_monthly: float,
     *     total_annual: float,
     *     categories: list<array{
     *         id: int,
     *         name: string,
     *         color: ?string,
     *         monthly_budget: ?float,
     *         annual_budget: ?float
     *     }>
     * }
     */
    public function planForUser(int $userId): array
    {
        /** @var Collection<int, BudgetCategoryLimit> $limits */
        $limits = BudgetCategoryLimit::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('category_id');

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('kind', Category::KIND_EXPENSE)
            ->orderBy('name')
            ->get()
            ->map(function (Category $category) use ($limits) {
                $limit = $limits->get($category->id);
                $monthly = $limit !== null ? round((float) $limit->amount, 2) : null;

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'color' => $category->color,
                    'monthly_budget' => $monthly,
                    'annual_budget' => $monthly !== null ? round($monthly * 12, 2) : null,
                ];
            })
            ->values()
            ->all();

        $totalMonthly = round(
            (float) collect($categories)->sum(fn (array $category) => (float) ($category['monthly_budget'] ?? 0)),
            2,
        );

        return [
            'year' => (int) Carbon::now()->year,
            'total_monthly' => $totalMonthly,
            'total_annual' => round($totalMonthly * 12, 2),
            'categories' => $categories,
        ];
    }

    /**
     * Replace the user's expense category monthly budgets.
     *
     * @param  array<int, float|string|null>  $limits  category_id => amount (null/blank removes)
     */
    public function syncLimits(int $userId, array $limits): void
    {
        $expenseCategoryIds = Category::query()
            ->where('user_id', $userId)
            ->where('kind', Category::KIND_EXPENSE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowed = array_flip($expenseCategoryIds);
        $upserts = [];
        $keepIds = [];

        foreach ($limits as $categoryId => $amount) {
            $categoryId = (int) $categoryId;

            if (! isset($allowed[$categoryId])) {
                continue;
            }

            if ($amount === null || $amount === '') {
                continue;
            }

            $normalized = round((float) $amount, 2);

            if ($normalized < 0) {
                continue;
            }

            $upserts[] = [
                'user_id' => $userId,
                'category_id' => $categoryId,
                'amount' => $normalized,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $keepIds[] = $categoryId;
        }

        BudgetCategoryLimit::query()
            ->where('user_id', $userId)
            ->when(
                $keepIds !== [],
                fn ($query) => $query->whereNotIn('category_id', $keepIds),
                fn ($query) => $query,
            )
            ->delete();

        foreach ($upserts as $row) {
            BudgetCategoryLimit::query()->updateOrCreate(
                [
                    'user_id' => $row['user_id'],
                    'category_id' => $row['category_id'],
                ],
                ['amount' => $row['amount']],
            );
        }
    }

    /**
     * @return array{
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     month: string,
     *     label: string,
     *     months_elapsed: int
     * }
     */
    public function resolvePeriod(string $view, ?string $month = null): array
    {
        $now = Carbon::now()->startOfDay();

        if ($view === 'ytm') {
            $from = $now->copy()->startOfYear();
            $to = $now->copy()->startOfMonth()->addMonth();
            $monthsElapsed = (int) $now->month;

            return [
                'from' => $from,
                'to' => $to,
                'month' => $now->format('Y-m'),
                'label' => $from->format('M Y').' – '.$now->copy()->endOfMonth()->format('M Y'),
                'months_elapsed' => $monthsElapsed,
            ];
        }

        $selected = $this->parseMonth($month) ?? $now->copy()->startOfMonth();
        $from = $selected->copy()->startOfMonth();
        $to = $from->copy()->addMonth();

        return [
            'from' => $from,
            'to' => $to,
            'month' => $from->format('Y-m'),
            'label' => $from->format('F Y'),
            'months_elapsed' => 1,
        ];
    }

    protected function parseMonth(?string $month): ?Carbon
    {
        if ($month === null || $month === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}
