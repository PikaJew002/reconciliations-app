<?php

namespace App\Services\Plans;

use App\Models\BudgetYear;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PlannedOccurrenceGenerator
{
    public const MONTHS_AHEAD = 2;

    public function ensureForUser(int $userId): void
    {
        $this->syncActiveTemplates($userId);
    }

    public function ensureAll(?int $userId = null): int
    {
        $userIds = PlannedTemplate::query()
            ->where('is_active', true)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->distinct()
            ->pluck('user_id');

        $synced = 0;

        foreach ($userIds as $id) {
            $synced += $this->syncActiveTemplates((int) $id);
        }

        return $synced;
    }

    public function syncTemplate(PlannedTemplate $template): void
    {
        $this->syncTemplateOccurrences($template);
    }

    protected function syncActiveTemplates(int $userId): int
    {
        $templates = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        if ($templates->isEmpty()) {
            return 0;
        }

        $leftoverStartsOn = User::query()
            ->whereKey($userId)
            ->value('leftover_starts_on');
        $leftoverStartsOn = $leftoverStartsOn !== null
            ? Carbon::parse($leftoverStartsOn)->startOfMonth()->startOfDay()
            : null;

        $occurrencesByTemplateId = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereIn('template_id', $templates->modelKeys())
            ->get()
            ->groupBy(fn (PlannedOccurrence $occurrence): int => (int) $occurrence->template_id);

        foreach ($templates as $template) {
            $this->syncTemplateOccurrences(
                $template,
                $occurrencesByTemplateId->get((int) $template->id, collect()),
                leftoverStartsOnKnown: true,
                leftoverStartsOn: $leftoverStartsOn,
            );
        }

        return $templates->count();
    }

    /**
     * @param  Collection<int, PlannedOccurrence>|null  $occurrences
     */
    protected function syncTemplateOccurrences(
        PlannedTemplate $template,
        ?Collection $occurrences = null,
        bool $leftoverStartsOnKnown = false,
        ?CarbonInterface $leftoverStartsOn = null,
    ): void {
        if (! $template->is_active) {
            return;
        }

        foreach ($this->monthsFrom(
            $this->historyStartForTemplate($template, $leftoverStartsOnKnown, $leftoverStartsOn),
            self::horizonLastMonth(),
        ) as $month) {
            $this->syncOccurrenceForMonth($template, $month, updateExisting: true, occurrences: $occurrences);
        }

        $this->pruneNonDuePlannedOccurrences($template, $occurrences);
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
    protected function historyStartForTemplate(
        PlannedTemplate $template,
        bool $leftoverStartsOnKnown = false,
        ?CarbonInterface $leftoverStartsOn = null,
    ): CarbonInterface {
        if ($template->occurrences_starts_on !== null) {
            return Carbon::parse($template->occurrences_starts_on)->startOfMonth()->startOfDay();
        }

        $created = $template->created_at
            ? Carbon::parse($template->created_at)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $start = $created->copy()->subMonth()->startOfDay();

        if (! $leftoverStartsOnKnown) {
            $leftoverStartsOn = User::query()
                ->whereKey($template->user_id)
                ->value('leftover_starts_on');
            $leftoverStartsOn = $leftoverStartsOn !== null
                ? Carbon::parse($leftoverStartsOn)->startOfMonth()->startOfDay()
                : null;
        }

        if ($leftoverStartsOn === null || ! $leftoverStartsOn->lt($start)) {
            return $start;
        }

        return $leftoverStartsOn->copy();
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

    /**
     * @param  Collection<int, PlannedOccurrence>|null  $occurrences
     */
    protected function syncOccurrenceForMonth(
        PlannedTemplate $template,
        CarbonInterface $month,
        bool $updateExisting,
        ?Collection $occurrences = null,
    ): bool {
        if (! $template->isDueInMonth($month)) {
            return false;
        }

        $scheduledDate = PlannedOccurrence::expectedDateForMonth($month, (int) $template->expected_day);

        $existing = $this->existingOccurrenceForMonth($template, $month, $occurrences);

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

        $created = PlannedOccurrence::query()->create([
            ...$attributes,
            'expected_date' => $scheduledDate->toDateString(),
            'date_customized' => false,
            'amount_customized' => false,
        ]);
        $occurrences?->push($created);

        return true;
    }

    /**
     * @param  Collection<int, PlannedOccurrence>|null  $occurrences
     */
    protected function existingOccurrenceForMonth(
        PlannedTemplate $template,
        CarbonInterface $month,
        ?Collection $occurrences,
    ): ?PlannedOccurrence {
        if ($occurrences !== null) {
            return $occurrences->first(
                fn (PlannedOccurrence $occurrence): bool => $occurrence->belongsToMonth($month),
            );
        }

        return PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->forPeriod($month)
            ->first();
    }

    /**
     * Monthly plans are due every month, so the planned-row scan cannot delete
     * anything. Longer recurrences still drop planned rows in months that are
     * no longer due.
     *
     * @param  Collection<int, PlannedOccurrence>|null  $occurrences
     */
    protected function pruneNonDuePlannedOccurrences(PlannedTemplate $template, ?Collection $occurrences = null): void
    {
        if ((int) $template->recurrence_months <= 1) {
            return;
        }

        $planned = $occurrences !== null
            ? $occurrences->filter(fn (PlannedOccurrence $occurrence): bool => $occurrence->isPlanned())
            : PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->where('status', PlannedOccurrence::STATUS_PLANNED)
                ->get();

        $planned->each(function (PlannedOccurrence $occurrence) use ($template): void {
            $period = ($occurrence->scheduled_date ?? $occurrence->expected_date)->copy()->startOfMonth();

            if (! $template->isDueInMonth($period)) {
                $occurrence->delete();
            }
        });
    }
}
