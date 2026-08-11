<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Reporting\CategorySpendQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, CategorySpendQuery $categorySpendQuery): Response
    {
        $userId = $request->user()->id;

        $spendTotals = $categorySpendQuery->categoryTotalsForUser($userId);

        foreach ($categorySpendQuery->orderComponentCategoryTotalsForUser($userId) as $categoryId => $amount) {
            $spendTotals[$categoryId] = round(($spendTotals[$categoryId] ?? 0) + $amount, 2);
        }

        $incomeTotals = $categorySpendQuery->incomeCategoryTotalsForUser($userId);

        $uncategorizedSpend = round(
            $categorySpendQuery->uncategorizedSpendForUser($userId)
            + $categorySpendQuery->orderComponentUncategorizedSpendForUser($userId),
            2,
        );
        $uncategorizedIncome = $categorySpendQuery->uncategorizedIncomeForUser($userId);

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        $billCategories = $this->categoryCards(
            $categories->where('kind', Category::KIND_BILL),
            $spendTotals,
        );
        $expenseCategories = $this->categoryCards(
            $categories->where('kind', Category::KIND_EXPENSE),
            $spendTotals,
        );
        $incomeCategories = $this->categoryCards(
            $categories->where('kind', Category::KIND_INCOME),
            $incomeTotals,
        );

        $billsAmount = round((float) collect($billCategories)->sum('amount'), 2);
        $expensesAmount = round((float) collect($expenseCategories)->sum('amount'), 2);
        $categorizedIncomeAmount = round((float) collect($incomeCategories)->sum('amount'), 2);

        $billCategories = $this->withKindPercents($billCategories, $billsAmount);
        $expenseCategories = $this->withKindPercents($expenseCategories, $expensesAmount);
        $incomeCategories = $this->withKindPercents($incomeCategories, $categorizedIncomeAmount + $uncategorizedIncome);

        $totalIncome = round($categorizedIncomeAmount + $uncategorizedIncome, 2);
        $totalSpend = round($billsAmount + $expensesAmount + $uncategorizedSpend, 2);

        return Inertia::render('Dashboard/Index', [
            'total_income' => $totalIncome,
            'total_spend' => $totalSpend,
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
                    ],
                    'expenses' => [
                        'amount' => $expensesAmount,
                        'categories' => $expenseCategories,
                    ],
                    'uncategorized' => $this->uncategorizedPayload($uncategorizedSpend, $totalSpend),
                ],
            ],
            'coverage' => $categorySpendQuery->spendCoverageForUser($userId),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
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
