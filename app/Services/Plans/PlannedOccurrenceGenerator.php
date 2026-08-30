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
        $keepMonths = [];

        foreach ($months as $month) {
            $scheduledDate = PlannedOccurrence::expectedDateForMonth($month, (int) $template->expected_day);
            $keepMonths[] = $month->format('Y-m');

            $existing = PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->forPeriod($month)
                ->first();

            if ($existing?->isResolved()) {
                continue;
            }

            $attributes = [
                'user_id' => $template->user_id,
                'template_id' => $template->id,
                'status' => PlannedOccurrence::STATUS_PLANNED,
                'scheduled_date' => $scheduledDate->toDateString(),
                ...$template->matchAttributes(),
            ];

            if ($existing !== null) {
                if ($existing->amount_customized) {
                    unset($attributes['expected_amount']);
                }

                if (! $existing->date_customized) {
                    $attributes['expected_date'] = $scheduledDate->toDateString();
                }

                $existing->update($attributes);

                continue;
            }

            PlannedOccurrence::query()->create([
                ...$attributes,
                'expected_date' => $scheduledDate->toDateString(),
                'date_customized' => false,
                'amount_customized' => false,
            ]);
        }

        PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->where('status', PlannedOccurrence::STATUS_PLANNED)
            ->get()
            ->each(function (PlannedOccurrence $occurrence) use ($keepMonths): void {
                $period = $occurrence->scheduled_date ?? $occurrence->expected_date;

                if (! in_array($period->format('Y-m'), $keepMonths, true)) {
                    $occurrence->delete();
                }
            });
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
     *
     * @return list<CarbonInterface>
     */
    protected function monthsInHorizon(): array
    {
        $start = Carbon::now()->startOfMonth()->subMonth()->startOfDay();
        $end = self::horizonLastMonth()->copy()->addMonth();

        $months = [];
        $cursor = $start->copy();

        while ($cursor->lt($end)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }
}
