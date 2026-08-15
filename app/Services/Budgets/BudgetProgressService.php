<?php

namespace App\Services\Budgets;

use App\Models\BudgetCategoryLimit;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BudgetProgressService
{
    public function __construct(
        protected CategorySpendQuery $categorySpendQuery,
    ) {}

    /**
     * @return array{
     *     view: string,
     *     month: string,
     *     budget_year: ?array{id: int, label: string, color: string, starts_on: string, ends_on: string, is_current: bool},
     *     budget_years: list<array{id: int, label: string, color: string, starts_on: string, ends_on: string, is_current: bool}>,
     *     period: array{from: string, to: string, label: string, months_elapsed: int},
     *     summary: array{
     *         income: float,
     *         income_received: float,
     *         income_planned: float,
     *         bills: float,
     *         leftover_income: float,
     *         expenses: float,
     *         income_budget_allowed: float,
     *         bills_budget_allowed: float,
     *         budget_allowed: float,
     *         income_vs_budget_difference: float,
     *         bills_vs_budget_difference: float,
     *         vs_budget_difference: float,
     *         vs_leftover_difference: float
     *     },
     *     categories: list<array{
     *         id: int,
     *         name: string,
     *         kind: string,
     *         color: ?string,
     *         monthly_budget: ?float,
     *         budget_allowed: ?float,
     *         spend: float,
     *         vs_budget_difference: ?float
     *     }>,
     *     uncategorized_expense: array{spend: float}
     * }
     */
    public function build(
        int $userId,
        string $view,
        ?string $month = null,
        ?int $budgetYearId = null,
    ): array {
        if (! in_array($view, ['month', 'ytm'], true)) {
            throw new InvalidArgumentException('View must be month or ytm.');
        }

        $years = $this->yearsForUser($userId);
        $selectedYear = $this->resolveYear($userId, $budgetYearId);
        $resolved = $this->resolvePeriod($userId, $view, $month, $selectedYear);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $monthsElapsed = $resolved['months_elapsed'];

        $limitsYear = $view === 'ytm'
            ? $selectedYear
            : $this->yearContainingMonth($userId, $resolved['month_start']);

        $spendTotals = $this->categorySpendQuery->categoryTotalsForUser($userId, $from, $to);

        foreach ($this->categorySpendQuery->orderComponentCategoryTotalsForUser($userId, $from, $to) as $categoryId => $amount) {
            $spendTotals[$categoryId] = round(($spendTotals[$categoryId] ?? 0) + $amount, 2);
        }

        $incomeTotals = $this->categorySpendQuery->incomeCategoryTotalsForUser($userId, $from, $to);

        $uncategorizedExpense = round(
            $this->categorySpendQuery->uncategorizedExpenseSpendForUser($userId, $from, $to)
            + $this->categorySpendQuery->orderComponentUncategorizedSpendForUser($userId, $from, $to),
            2,
        );

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        $incomeCategories = $categories->where('kind', Category::KIND_INCOME);
        $billCategories = $categories->where('kind', Category::KIND_BILL);
        $expenseCategories = $categories->where('kind', Category::KIND_EXPENSE);

        $billsAmount = round(
            (float) $billCategories->sum(fn (Category $category) => (float) ($spendTotals[$category->id] ?? 0))
            + $this->categorySpendQuery->uncategorizedBillSpendForUser($userId, $from, $to),
            2,
        );

        $incomeBreakdown = $this->categorySpendQuery->incomeBreakdownForUser($userId, $from, $to);
        $income = $incomeBreakdown['total'];
        $leftoverIncome = round($income - $billsAmount, 2);

        /** @var Collection<int, BudgetCategoryLimit> $limits */
        $limits = $limitsYear === null
            ? collect()
            : BudgetCategoryLimit::query()
                ->where('user_id', $userId)
                ->where('budget_year_id', $limitsYear->id)
                ->get()
                ->keyBy('category_id');

        $budgetMultiplier = $view === 'ytm' ? $monthsElapsed : 1;

        $incomeRows = $this->budgetCategoryRows(
            $incomeCategories,
            $incomeTotals,
            $limits,
            $limitsYear,
            $budgetMultiplier,
            surplusStyle: true,
        );
        $billRows = $this->budgetCategoryRows(
            $billCategories,
            $spendTotals,
            $limits,
            $limitsYear,
            $budgetMultiplier,
            surplusStyle: false,
        );
        $expenseRows = $this->budgetCategoryRows(
            $expenseCategories,
            $spendTotals,
            $limits,
            $limitsYear,
            $budgetMultiplier,
            surplusStyle: false,
        );

        $categorizedExpenseSpend = round(
            (float) collect($expenseRows)->sum('spend'),
            2,
        );
        $totalExpenses = round($categorizedExpenseSpend + $uncategorizedExpense, 2);

        $incomeBudgetAllowed = $this->sumBudgetAllowed($incomeRows);
        $billsBudgetAllowed = $this->sumBudgetAllowed($billRows);
        $expenseBudgetAllowed = $this->sumBudgetAllowed($expenseRows);

        return [
            'view' => $view,
            'month' => $resolved['month'],
            'budget_year' => $selectedYear?->toPayload(),
            'budget_years' => $years->map(fn (BudgetYear $year) => $year->toPayload())->values()->all(),
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->copy()->subDay()->toDateString(),
                'label' => $resolved['label'],
                'months_elapsed' => $monthsElapsed,
            ],
            'summary' => [
                'income' => $income,
                'income_received' => $incomeBreakdown['received'],
                'income_planned' => $incomeBreakdown['planned'],
                'bills' => $billsAmount,
                'leftover_income' => $leftoverIncome,
                'expenses' => $totalExpenses,
                'income_budget_allowed' => $incomeBudgetAllowed,
                'bills_budget_allowed' => $billsBudgetAllowed,
                'budget_allowed' => $expenseBudgetAllowed,
                'income_vs_budget_difference' => round($income - $incomeBudgetAllowed, 2),
                'bills_vs_budget_difference' => round($billsBudgetAllowed - $billsAmount, 2),
                'vs_budget_difference' => round($expenseBudgetAllowed - $totalExpenses, 2),
                'vs_leftover_difference' => round($leftoverIncome - $totalExpenses, 2),
            ],
            'categories' => [...$incomeRows, ...$billRows, ...$expenseRows],
            'uncategorized_expense' => [
                'spend' => $uncategorizedExpense,
            ],
        ];
    }

    /**
     * @return array{
     *     budget_year: ?array{id: int, label: string, color: string, starts_on: string, ends_on: string, is_current: bool},
     *     budget_years: list<array{id: int, label: string, color: string, starts_on: string, ends_on: string, is_current: bool}>,
     *     total_monthly: float,
     *     total_annual: float,
     *     sections: array{
     *         income: list<array{id: int, name: string, kind: string, color: ?string, monthly_budget: ?float, annual_budget: ?float}>,
     *         bills: list<array{id: int, name: string, kind: string, color: ?string, monthly_budget: ?float, annual_budget: ?float}>,
     *         expenses: list<array{id: int, name: string, kind: string, color: ?string, monthly_budget: ?float, annual_budget: ?float}>
     *     },
     *     categories: list<array{id: int, name: string, kind: string, color: ?string, monthly_budget: ?float, annual_budget: ?float}>
     * }
     */
    public function planForUser(int $userId, ?int $budgetYearId = null): array
    {
        $years = $this->yearsForUser($userId);
        $selectedYear = $this->resolveYear($userId, $budgetYearId);

        /** @var Collection<int, BudgetCategoryLimit> $limits */
        $limits = $selectedYear === null
            ? collect()
            : BudgetCategoryLimit::query()
                ->where('user_id', $userId)
                ->where('budget_year_id', $selectedYear->id)
                ->get()
                ->keyBy('category_id');

        $mapCategory = function (Category $category) use ($limits): array {
            $limit = $limits->get($category->id);
            $monthly = $limit !== null ? round((float) $limit->amount, 2) : null;

            return [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'color' => $category->color,
                'monthly_budget' => $monthly,
                'annual_budget' => $monthly !== null ? round($monthly * 12, 2) : null,
            ];
        };

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        $income = $categories->where('kind', Category::KIND_INCOME)->map($mapCategory)->values()->all();
        $bills = $categories->where('kind', Category::KIND_BILL)->map($mapCategory)->values()->all();
        $expenses = $categories->where('kind', Category::KIND_EXPENSE)->map($mapCategory)->values()->all();
        $all = [...$income, ...$bills, ...$expenses];

        $totalMonthly = round(
            (float) collect($all)->sum(fn (array $category) => (float) ($category['monthly_budget'] ?? 0)),
            2,
        );

        return [
            'budget_year' => $selectedYear?->toPayload(),
            'budget_years' => $years->map(fn (BudgetYear $year) => $year->toPayload())->values()->all(),
            'total_monthly' => $totalMonthly,
            'total_annual' => round($totalMonthly * 12, 2),
            'sections' => [
                'income' => $income,
                'bills' => $bills,
                'expenses' => $expenses,
            ],
            'categories' => $all,
        ];
    }

    /**
     * @param  array<int, float|string|null>  $limits
     */
    public function syncLimits(int $userId, int $budgetYearId, array $limits): void
    {
        $year = $this->ownedYear($userId, $budgetYearId);

        $categoryIds = Category::query()
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowed = array_flip($categoryIds);
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

            BudgetCategoryLimit::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'budget_year_id' => $year->id,
                    'category_id' => $categoryId,
                ],
                ['amount' => $normalized],
            );
            $keepIds[] = $categoryId;
        }

        BudgetCategoryLimit::query()
            ->where('user_id', $userId)
            ->where('budget_year_id', $year->id)
            ->when(
                $keepIds !== [],
                fn ($query) => $query->whereNotIn('category_id', $keepIds),
                fn ($query) => $query,
            )
            ->delete();
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  array<int, float>  $amounts
     * @param  Collection<int, BudgetCategoryLimit>  $limits
     * @return list<array{
     *     id: int,
     *     name: string,
     *     kind: string,
     *     color: ?string,
     *     monthly_budget: ?float,
     *     budget_allowed: ?float,
     *     spend: float,
     *     vs_budget_difference: ?float
     * }>
     */
    protected function budgetCategoryRows(
        $categories,
        array $amounts,
        Collection $limits,
        ?BudgetYear $limitsYear,
        int $budgetMultiplier,
        bool $surplusStyle,
    ): array {
        $rows = [];

        foreach ($categories as $category) {
            $spend = round((float) ($amounts[$category->id] ?? 0), 2);
            $limit = $limits->get($category->id);
            $monthlyBudget = $limit !== null ? round((float) $limit->amount, 2) : null;
            $budgetAllowed = ($monthlyBudget !== null && $limitsYear !== null && $budgetMultiplier > 0)
                ? round($monthlyBudget * $budgetMultiplier, 2)
                : null;

            $vsBudget = null;

            if ($budgetAllowed !== null) {
                $vsBudget = $surplusStyle
                    ? round($spend - $budgetAllowed, 2)
                    : round($budgetAllowed - $spend, 2);
            }

            $rows[] = [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'color' => $category->color,
                'monthly_budget' => $monthlyBudget,
                'budget_allowed' => $budgetAllowed,
                'spend' => $spend,
                'vs_budget_difference' => $vsBudget,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{budget_allowed: ?float}>  $rows
     */
    protected function sumBudgetAllowed(array $rows): float
    {
        return round(
            (float) collect($rows)->sum(fn (array $row) => (float) ($row['budget_allowed'] ?? 0)),
            2,
        );
    }

    public function createYear(
        int $userId,
        string $startMonth,
        string $color,
        ?string $label = null,
        bool $makeCurrent = false,
    ): BudgetYear {
        $startsOn = $this->parseMonth($startMonth);

        if ($startsOn === null) {
            throw ValidationException::withMessages([
                'starts_on' => 'Start month must be a valid YYYY-MM value.',
            ]);
        }

        $this->assertNoOverlap($userId, $startsOn);

        $hasCurrent = BudgetYear::query()
            ->where('user_id', $userId)
            ->where('is_current', true)
            ->exists();

        $shouldBeCurrent = $makeCurrent || ! $hasCurrent;

        if ($shouldBeCurrent) {
            BudgetYear::query()
                ->where('user_id', $userId)
                ->where('is_current', true)
                ->update(['is_current' => false]);
        }

        return BudgetYear::query()->create([
            'user_id' => $userId,
            'starts_on' => $startsOn->toDateString(),
            'label' => $label !== null && trim($label) !== ''
                ? trim($label)
                : BudgetYear::labelForStart($startsOn),
            'color' => $color,
            'is_current' => $shouldBeCurrent,
        ]);
    }

    /**
     * @param  array{label?: string, color?: string, starts_on?: string}  $attributes
     */
    public function updateYear(int $userId, int $budgetYearId, array $attributes): BudgetYear
    {
        $year = $this->ownedYear($userId, $budgetYearId);

        if (isset($attributes['starts_on'])) {
            $startsOn = $this->parseMonth($attributes['starts_on']);

            if ($startsOn === null) {
                throw ValidationException::withMessages([
                    'starts_on' => 'Start month must be a valid YYYY-MM value.',
                ]);
            }

            $this->assertNoOverlap($userId, $startsOn, $year->id);
            $year->starts_on = $startsOn->toDateString();

            if (! isset($attributes['label'])) {
                $year->label = BudgetYear::labelForStart($startsOn);
            }
        }

        if (array_key_exists('label', $attributes) && $attributes['label'] !== null) {
            $trimmed = trim((string) $attributes['label']);

            if ($trimmed !== '') {
                $year->label = $trimmed;
            }
        }

        if (isset($attributes['color'])) {
            $year->color = $attributes['color'];
        }

        $year->save();

        return $year->refresh();
    }

    public function setCurrentYear(int $userId, int $budgetYearId): BudgetYear
    {
        $year = $this->ownedYear($userId, $budgetYearId);

        BudgetYear::query()
            ->where('user_id', $userId)
            ->where('is_current', true)
            ->where('id', '!=', $year->id)
            ->update(['is_current' => false]);

        $year->is_current = true;
        $year->save();

        return $year->refresh();
    }

    public function resolveYear(int $userId, ?int $budgetYearId = null): ?BudgetYear
    {
        if ($budgetYearId !== null) {
            return $this->ownedYear($userId, $budgetYearId);
        }

        return BudgetYear::query()
            ->where('user_id', $userId)
            ->where('is_current', true)
            ->first()
            ?? BudgetYear::query()
                ->where('user_id', $userId)
                ->orderByDesc('starts_on')
                ->first();
    }

    /**
     * @return Collection<int, BudgetYear>
     */
    public function yearsForUser(int $userId): Collection
    {
        return BudgetYear::query()
            ->where('user_id', $userId)
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * @return array{
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     month: string,
     *     month_start: CarbonInterface,
     *     label: string,
     *     months_elapsed: int
     * }
     */
    public function resolvePeriod(
        int $userId,
        string $view,
        ?string $month = null,
        ?BudgetYear $selectedYear = null,
    ): array {
        $now = Carbon::now()->startOfDay();
        $selectedYear ??= $this->resolveYear($userId);

        if ($view === 'ytm') {
            if ($selectedYear === null) {
                $from = $now->copy()->startOfMonth();
                $to = $from->copy()->addMonth();

                return [
                    'from' => $from,
                    'to' => $to,
                    'month' => $now->format('Y-m'),
                    'month_start' => $now->copy()->startOfMonth(),
                    'label' => 'No budget year',
                    'months_elapsed' => 0,
                ];
            }

            $planStart = $selectedYear->startsOn();
            $planEndExclusive = $selectedYear->endsOnExclusive();
            $currentMonthStart = $now->copy()->startOfMonth();
            $currentMonthEndExclusive = $currentMonthStart->copy()->addMonth();

            if ($currentMonthStart->lt($planStart)) {
                return [
                    'from' => $planStart->copy(),
                    'to' => $planStart->copy(),
                    'month' => $now->format('Y-m'),
                    'month_start' => $currentMonthStart,
                    'label' => $selectedYear->label,
                    'months_elapsed' => 0,
                ];
            }

            if ($currentMonthStart->gte($planEndExclusive)) {
                return [
                    'from' => $planStart->copy(),
                    'to' => $planEndExclusive->copy(),
                    'month' => $planEndExclusive->copy()->subMonth()->format('Y-m'),
                    'month_start' => $planEndExclusive->copy()->subMonth(),
                    'label' => $selectedYear->label,
                    'months_elapsed' => 12,
                ];
            }

            $to = $currentMonthEndExclusive->lt($planEndExclusive)
                ? $currentMonthEndExclusive
                : $planEndExclusive->copy();

            $monthsElapsed = $this->monthsBetween($planStart, $to->copy()->subMonth());

            return [
                'from' => $planStart->copy(),
                'to' => $to,
                'month' => $currentMonthStart->format('Y-m'),
                'month_start' => $currentMonthStart,
                'label' => $planStart->format('M Y').' – '.$to->copy()->subDay()->format('M Y'),
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
            'month_start' => $from,
            'label' => $from->format('F Y'),
            'months_elapsed' => 1,
        ];
    }

    public function yearContainingMonth(int $userId, CarbonInterface $month): ?BudgetYear
    {
        $monthStart = $month->copy()->startOfMonth()->startOfDay();

        $candidates = BudgetYear::query()
            ->where('user_id', $userId)
            ->where('starts_on', '<=', $monthStart->toDateString())
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->get();

        return $candidates->first(
            fn (BudgetYear $year) => $year->containsMonth($monthStart),
        );
    }

    protected function ownedYear(int $userId, int $budgetYearId): BudgetYear
    {
        $year = BudgetYear::query()
            ->where('user_id', $userId)
            ->whereKey($budgetYearId)
            ->first();

        if ($year === null) {
            throw new NotFoundHttpException;
        }

        return $year;
    }

    protected function assertNoOverlap(
        int $userId,
        CarbonInterface $startsOn,
        ?int $ignoreId = null,
    ): void {
        $newStart = $startsOn->copy()->startOfMonth();
        $newEnd = $newStart->copy()->addYear();

        $others = BudgetYear::query()
            ->where('user_id', $userId)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get();

        foreach ($others as $other) {
            $otherStart = $other->startsOn();
            $otherEnd = $other->endsOnExclusive();

            if ($newStart->lt($otherEnd) && $newEnd->gt($otherStart)) {
                throw ValidationException::withMessages([
                    'starts_on' => 'Budget years cannot overlap.',
                ]);
            }
        }
    }

    protected function monthsBetween(CarbonInterface $startMonth, CarbonInterface $endMonth): int
    {
        $start = $startMonth->copy()->startOfMonth();
        $end = $endMonth->copy()->startOfMonth();

        if ($end->lt($start)) {
            return 0;
        }

        return ((int) $start->diffInMonths($end)) + 1;
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
