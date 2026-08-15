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

        $months = $this->monthsInHorizon();
        $keepDates = [];

        foreach ($months as $month) {
            $expectedDate = PlannedOccurrence::expectedDateForMonth($month, (int) $template->expected_day);
            $keepDates[] = $expectedDate->toDateString();

            $existing = PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', $expectedDate->toDateString())
                ->first();

            if ($existing?->isResolved()) {
                continue;
            }

            $attributes = [
                'user_id' => $template->user_id,
                'template_id' => $template->id,
                'status' => PlannedOccurrence::STATUS_PLANNED,
                'expected_date' => $expectedDate->toDateString(),
                ...$template->matchAttributes(),
            ];

            if ($existing !== null) {
                $existing->update($attributes);

                continue;
            }

            PlannedOccurrence::query()->create($attributes);
        }

        PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->where('status', PlannedOccurrence::STATUS_PLANNED)
            ->get()
            ->each(function (PlannedOccurrence $occurrence) use ($keepDates): void {
                if (! in_array($occurrence->expected_date->toDateString(), $keepDates, true)) {
                    $occurrence->delete();
                }
            });
    }

    /**
     * Last month through two months ahead. Future months stay ungenerated
     * so the template can change mid-year before those records exist.
     *
     * @return list<CarbonInterface>
     */
    protected function monthsInHorizon(): array
    {
        $start = Carbon::now()->startOfMonth()->subMonth()->startOfDay();
        $end = Carbon::now()->startOfMonth()->addMonths(self::MONTHS_AHEAD + 1);

        $months = [];
        $cursor = $start->copy();

        while ($cursor->lt($end)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }
}
