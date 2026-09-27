<?php

namespace App\Services\Plans;

use App\Models\BudgetYear;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PlannedOccurrenceGenerator
{
    public const MONTHS_AHEAD = 2;

    public function ensureForUser(int $userId): void
    {
        $templates = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        foreach ($templates as $template) {
            $this->syncTemplate($template);
        }
    }

    public function ensureAll(?int $userId = null): int
    {
        $query = PlannedTemplate::query()->where('is_active', true);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $synced = 0;

        foreach ($query->cursor() as $template) {
            $this->syncTemplate($template);
            $synced++;
        }

        return $synced;
    }

    public function syncTemplate(PlannedTemplate $template): void
    {
        if (! $template->is_active) {
            return;
        }

        foreach ($this->monthsFrom($this->historyStartForTemplate($template), self::horizonLastMonth()) as $month) {
            $this->syncOccurrenceForMonth($template, $month, updateExisting: true);
        }

        $this->pruneNonDuePlannedOccurrences($template);
    }

    /**
     * Recreate missing months back to leftover tracking start (when set) or
     * the month before the plan was created, through the current horizon.
     */
    public function backfillAll(?int $userId = null): int
    {
        $query = PlannedTemplate::query()->where('is_active', true);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $created = 0;

        foreach ($query->cursor() as $template) {
            $created += $this->backfillTemplate($template);
        }

        return $created;
    }

    public function backfillTemplate(PlannedTemplate $template): int
    {
        if (! $template->is_active) {
            return 0;
        }

        $created = 0;

        foreach ($this->monthsFrom($this->historyStartForTemplate($template), self::horizonLastMonth()) as $month) {
            if ($this->syncOccurrenceForMonth($template, $month, updateExisting: false)) {
                $created++;
            }
        }

        return $created;
    }

    public static function horizonLastMonth(): CarbonInterface
    {
        return Carbon::now()->startOfMonth()->addMonths(self::MONTHS_AHEAD);
    }

    public static function isBeyondHorizon(CarbonInterface $month): bool
    {
        return $month->copy()->startOfMonth()->startOfDay()
            ->gt(self::horizonLastMonth());
    }

    public function earliestBillOccurrenceStartMonth(int $userId): CarbonInterface
    {
        $currentMonth = Carbon::now()->startOfMonth()->startOfDay();

        $budgetYear = BudgetYear::query()
            ->where('user_id', $userId)
            ->where('is_current', true)
            ->first()
            ?? BudgetYear::query()
                ->where('user_id', $userId)
                ->get()
                ->first(fn (BudgetYear $year) => $year->containsMonth($currentMonth));

        if ($budgetYear !== null) {
            return $budgetYear->startsOn();
        }

        $leftoverStartsOn = User::query()
            ->whereKey($userId)
            ->value('leftover_starts_on');

        if ($leftoverStartsOn !== null) {
            return Carbon::parse($leftoverStartsOn)->startOfMonth()->startOfDay();
        }

        return $currentMonth->copy();
    }

    public function defaultBillOccurrenceStartMonth(int $userId): string
    {
        return $this->earliestBillOccurrenceStartMonth($userId)->format('Y-m');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function billOccurrenceStartMonthOptions(int $userId): array
    {
        $current = Carbon::now()->startOfMonth()->startOfDay();
        $cursor = $this->earliestBillOccurrenceStartMonth($userId);
        $options = [];

        while ($cursor->lte($current)) {
            $options[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $cursor->format('F Y'),
            ];
            $cursor = $cursor->copy()->addMonth();
        }

        return $options;
    }

    /**
     * Earliest month to generate for a plan. Bill plans use their own
     * occurrences_starts_on. Paycheck plans fall back to leftover tracking
     * start or the month before the plan was created.
     */
    protected function historyStartForTemplate(PlannedTemplate $template): CarbonInterface
    {
        if ($template->occurrences_starts_on !== null) {
            return Carbon::parse($template->occurrences_starts_on)->startOfMonth()->startOfDay();
        }

        $created = $template->created_at
            ? Carbon::parse($template->created_at)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $start = $created->copy()->subMonth()->startOfDay();

        $leftoverStartsOn = User::query()
            ->whereKey($template->user_id)
            ->value('leftover_starts_on');

        if ($leftoverStartsOn === null) {
            return $start;
        }

        $leftoverStart = Carbon::parse($leftoverStartsOn)->startOfMonth()->startOfDay();

        return $leftoverStart->lt($start) ? $leftoverStart : $start;
    }

    /**
     * @return list<CarbonInterface>
     */
    protected function monthsFrom(CarbonInterface $start, CarbonInterface $lastMonth): array
    {
        $cursor = $start->copy()->startOfMonth()->startOfDay();
        $end = $lastMonth->copy()->startOfMonth()->startOfDay()->addMonth();

        if ($cursor->gte($end)) {
            return [];
        }

        $months = [];

        while ($cursor->lt($end)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }

    protected function syncOccurrenceForMonth(
        PlannedTemplate $template,
        CarbonInterface $month,
        bool $updateExisting,
    ): bool {
        if (! $template->isDueInMonth($month)) {
            return false;
        }

        $scheduledDate = PlannedOccurrence::expectedDateForMonth($month, (int) $template->expected_day);

        $existing = PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->forPeriod($month)
            ->first();

        if ($existing?->isResolved()) {
            return false;
        }

        $attributes = [
            'user_id' => $template->user_id,
            'template_id' => $template->id,
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'scheduled_date' => $scheduledDate->toDateString(),
            ...$template->matchAttributes(),
        ];

        if ($existing !== null) {
            if ($updateExisting) {
                if ($existing->amount_customized) {
                    unset($attributes['expected_amount']);
                }

                if (! $existing->date_customized) {
                    $attributes['expected_date'] = $scheduledDate->toDateString();
                }

                $existing->update($attributes);
            }

            return false;
        }

        PlannedOccurrence::query()->create([
            ...$attributes,
            'expected_date' => $scheduledDate->toDateString(),
            'date_customized' => false,
            'amount_customized' => false,
        ]);

        return true;
    }

    protected function pruneNonDuePlannedOccurrences(PlannedTemplate $template): void
    {
        PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->where('status', PlannedOccurrence::STATUS_PLANNED)
            ->get()
            ->each(function (PlannedOccurrence $occurrence) use ($template): void {
                $period = ($occurrence->scheduled_date ?? $occurrence->expected_date)->copy()->startOfMonth();

                if (! $template->isDueInMonth($period)) {
                    $occurrence->delete();
                }
            });
    }
}
