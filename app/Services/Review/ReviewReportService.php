<?php

namespace App\Services\Review;

use App\Models\Category;
use App\Services\Budgets\BudgetProgressService;
use App\Services\Plans\PaycheckLeftoverService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ReviewReportService
{
    public function __construct(
        protected ReviewWeek $week,
        protected ReviewSlideBuilder $slides,
        protected BudgetProgressService $budgetProgress,
        protected PaycheckLeftoverService $leftover,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        int $userId,
        ?string $week,
        string $pass = ReviewSlideBuilder::PASS_DEFAULT,
        ?CarbonInterface $today = null,
    ): array {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $resolved = $this->week->resolve($week, $today);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $month = $this->week->monthForWeek($from, $to);
        $deck = $this->slides->build($userId, $from, $to, $pass);
        $progress = $this->budgetProgress->build($userId, 'month', $month);
        $leftover = $this->leftover->current($userId, $today);
        $pace = $this->pace($deck['week_spend'], $progress['summary'], $leftover, $today, $month);

        return [
            'week' => [
                'from' => $from->toDateString(),
                'to' => $to->copy()->subDay()->toDateString(),
                'week' => $resolved['week'],
                'label' => $resolved['label'],
                'previous_week' => $resolved['previous_week'],
                'next_week' => $resolved['next_week'],
                'is_complete' => $resolved['is_complete'],
            ],
            'pass' => in_array($pass, [ReviewSlideBuilder::PASS_DEFAULT, ReviewSlideBuilder::PASS_ALL], true)
                ? $pass
                : ReviewSlideBuilder::PASS_DEFAULT,
            'week_spend' => $deck['week_spend'],
            'slides' => $deck['slides'],
            'expected_bills' => $deck['expected_bills'],
            'month' => $month,
            'month_summary' => [
                'label' => $progress['period']['label'],
                'from' => $progress['period']['from'],
                'to' => $progress['period']['to'],
                'income' => $progress['summary']['income'],
                'leftover_income' => $progress['summary']['leftover_income'],
                'expenses' => $progress['summary']['expenses'],
                'bills' => $progress['summary']['bills'],
                'budget_allowed' => $progress['summary']['budget_allowed'],
                'vs_budget_difference' => $progress['summary']['vs_budget_difference'],
                'vs_leftover_difference' => $progress['summary']['vs_leftover_difference'],
            ],
            'paycheck_leftover' => $leftover,
            'pace' => $pace,
            'course_corrections' => $this->courseCorrections(
                $progress['summary'],
                $progress['categories'],
                $leftover,
                $pace,
            ),
            'categories' => $this->categoriesForUser($userId),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>|null  $leftover
     * @return array<string, mixed>
     */
    protected function pace(
        float $weekSpend,
        array $summary,
        ?array $leftover,
        CarbonInterface $today,
        string $month,
    ): array {
        $dailyRate = round($weekSpend / 7, 2);
        $daysRemaining = $leftover['days_remaining'] ?? null;
        $paycheckRemaining = $leftover['paycheck_remaining'] ?? $leftover['remaining'] ?? null;
        $projectedToPaycheck = $daysRemaining !== null
            ? round($dailyRate * (int) $daysRemaining, 2)
            : null;
        $monthStart = Carbon::parse($month.'-01')->startOfMonth();
        $monthEndExclusive = $monthStart->copy()->addMonth();
        $daysLeftInMonth = (int) max(0, $today->diffInDays($monthEndExclusive, false));
        $projectedMonthExpenses = round($summary['expenses'] + ($dailyRate * $daysLeftInMonth), 2);

        return [
            'daily_rate' => $dailyRate,
            'days_remaining' => $daysRemaining,
            'paycheck_remaining' => $paycheckRemaining !== null ? (float) $paycheckRemaining : null,
            'projected_to_paycheck' => $projectedToPaycheck,
            'paycheck_on_track' => $paycheckRemaining !== null && $projectedToPaycheck !== null
                ? (float) $paycheckRemaining >= $projectedToPaycheck
                : null,
            'days_left_in_month' => $daysLeftInMonth,
            'projected_month_expenses' => $projectedMonthExpenses,
            'month_on_track_budget' => $projectedMonthExpenses <= (float) $summary['budget_allowed'],
            'month_on_track_leftover' => $projectedMonthExpenses <= (float) $summary['leftover_income'],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $categories
     * @param  array<string, mixed>|null  $leftover
     * @param  array<string, mixed>  $pace
     * @return list<array{kind: string, title: string, detail: string}>
     */
    protected function courseCorrections(
        array $summary,
        array $categories,
        ?array $leftover,
        array $pace,
    ): array {
        $corrections = [];
        $paycheckRemaining = $leftover['paycheck_remaining'] ?? $leftover['remaining'] ?? null;

        if ($paycheckRemaining !== null && (float) $paycheckRemaining < 0) {
            $corrections[] = [
                'kind' => 'leftover',
                'title' => 'Already into the next paycheck',
                'detail' => 'This window is overspent. Discretionary spend has to stop or the next check starts behind.',
            ];
        } elseif ($pace['paycheck_on_track'] === false) {
            $corrections[] = [
                'kind' => 'leftover',
                'title' => 'This week’s pace misses the next check',
                'detail' => 'At this week’s daily rate, leftover will run out before the next paycheck.',
            ];
        }

        if ((float) $summary['vs_leftover_difference'] < 0) {
            $corrections[] = [
                'kind' => 'month_leftover',
                'title' => 'Expenses are past leftover income',
                'detail' => 'This month has already spent more than income after bills.',
            ];
        } elseif ($pace['month_on_track_leftover'] === false) {
            $corrections[] = [
                'kind' => 'month_leftover',
                'title' => 'This week’s pace overshoots leftover income',
                'detail' => 'At this rate, expenses will pass leftover income before the month ends.',
            ];
        }

        if ((float) $summary['vs_budget_difference'] < 0) {
            $corrections[] = [
                'kind' => 'month_budget',
                'title' => 'Expenses are past the monthly budget',
                'detail' => 'The expense budget for this month is already used up.',
            ];
        } elseif ($pace['month_on_track_budget'] === false) {
            $corrections[] = [
                'kind' => 'month_budget',
                'title' => 'This week’s pace overshoots the expense budget',
                'detail' => 'At this rate, expenses will pass the monthly budget before the month ends.',
            ];
        }

        $overBudget = collect($categories)
            ->filter(fn (array $category): bool => $category['kind'] === Category::KIND_EXPENSE
                && $category['vs_budget_difference'] !== null
                && (float) $category['vs_budget_difference'] < 0)
            ->sortBy('vs_budget_difference')
            ->take(2);

        foreach ($overBudget as $category) {
            $corrections[] = [
                'kind' => 'category',
                'title' => $category['name'].' is over budget',
                'detail' => 'This month’s spend in '.$category['name'].' is past its limit.',
            ];
        }

        return array_values($corrections);
    }

    /**
     * @return list<array{id: int, name: string, kind: string, color: ?string}>
     */
    protected function categoriesForUser(int $userId): array
    {
        return Category::query()
            ->where('user_id', $userId)
            ->whereIn('kind', [Category::KIND_BILL, Category::KIND_EXPENSE])
            ->orderBy('kind')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'color' => $category->color,
            ])
            ->values()
            ->all();
    }
}
