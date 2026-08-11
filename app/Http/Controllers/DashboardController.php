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
        $totals = $categorySpendQuery->categoryTotalsForUser($userId);

        foreach ($categorySpendQuery->orderComponentCategoryTotalsForUser($userId) as $categoryId => $amount) {
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $amount, 2);
        }

        $uncategorizedAmount = round(
            $categorySpendQuery->uncategorizedSpendForUser($userId)
            + $categorySpendQuery->orderComponentUncategorizedSpendForUser($userId),
            2,
        );

        $categories = Category::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'amount' => round((float) ($totals[$category->id] ?? 0), 2),
            ]);

        $billsAmount = round(
            (float) $categories
                ->where('kind', Category::KIND_BILL)
                ->sum('amount'),
            2,
        );
        $expensesAmount = round(
            (float) $categories
                ->where('kind', Category::KIND_EXPENSE)
                ->sum('amount'),
            2,
        );

        $categories = $categories
            ->map(function (array $category) use ($billsAmount, $expensesAmount): array {
                $kindTotal = $category['kind'] === Category::KIND_BILL
                    ? $billsAmount
                    : $expensesAmount;

                $category['percent'] = $this->percentOf($category['amount'], $kindTotal);

                return $category;
            })
            ->sortBy([
                ['amount', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();

        $categorizedTotal = round($billsAmount + $expensesAmount, 2);
        $totalSpend = round($categorizedTotal + $uncategorizedAmount, 2);

        return Inertia::render('Dashboard/Index', [
            'categories' => $categories,
            'uncategorized_amount' => $uncategorizedAmount,
            'uncategorized_percent' => $this->percentOf($uncategorizedAmount, $totalSpend),
            'total_spend' => $totalSpend,
            'breakdown' => [
                'bills' => [
                    'amount' => $billsAmount,
                    'percent' => $this->percentOf($billsAmount, $totalSpend),
                ],
                'expenses' => [
                    'amount' => $expensesAmount,
                    'percent' => $this->percentOf($expensesAmount, $totalSpend),
                ],
                'uncategorized' => [
                    'amount' => $uncategorizedAmount,
                    'percent' => $this->percentOf($uncategorizedAmount, $totalSpend),
                ],
            ],
            'coverage' => $categorySpendQuery->spendCoverageForUser($userId),
        ]);
    }

    protected function percentOf(float $part, float $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round(($part / $whole) * 100, 1);
    }
}
