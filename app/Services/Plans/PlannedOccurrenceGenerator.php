<?php

namespace App\Services\Plans;

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

    /**
     * Earliest month to generate for a plan: leftover tracking start when set,
     * otherwise the month before the plan was created (the original horizon
     * start when the plan first appeared).
     */
    protected function historyStartForTemplate(PlannedTemplate $template): CarbonInterface
    {
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
}
