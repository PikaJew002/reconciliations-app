<?php

namespace App\Services\Plans;

use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
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

        foreach ($this->monthsInHorizon() as $month) {
            $this->syncOccurrenceForMonth($template, $month, updateExisting: true);
        }
    }

    /**
     * Recreate months that were generated when each plan existed, then later
     * pruned. Starts at the month before the plan was created (the original
     * horizon start) and stops at the current horizon.
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

        foreach ($this->monthsFrom($this->firstGeneratedMonth($template), self::horizonLastMonth()) as $month) {
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
     * Last month through two months ahead. Future months stay ungenerated
     * so the template can change mid-year before those records exist.
     * Existing occurrences outside this window are left in place.
     *
     * @return list<CarbonInterface>
     */
    protected function monthsInHorizon(): array
    {
        return $this->monthsFrom(
            Carbon::now()->startOfMonth()->subMonth()->startOfDay(),
            self::horizonLastMonth(),
        );
    }

    /**
     * When the plan was created, generation started at last month.
     */
    protected function firstGeneratedMonth(PlannedTemplate $template): CarbonInterface
    {
        $created = $template->created_at
            ? Carbon::parse($template->created_at)->startOfMonth()
            : Carbon::now()->startOfMonth();

        return $created->subMonth()->startOfDay();
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
