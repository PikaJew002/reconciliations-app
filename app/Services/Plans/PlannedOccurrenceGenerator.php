<?php

namespace App\Services\Plans;

use App\Models\BudgetYear;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PlannedOccurrenceGenerator
{
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

    /**
     * @param  list<array{category_id: int, expected_amount: float|int|string}>  $bills
     */
    public function syncTemplateBills(PlannedTemplate $template, array $bills): void
    {
        $template->bills()->delete();

        foreach ($bills as $bill) {
            $template->bills()->create([
                'category_id' => $bill['category_id'],
                'expected_amount' => $bill['expected_amount'],
            ]);
        }

        $template->unsetRelation('bills');
    }

    public function syncTemplate(PlannedTemplate $template): void
    {
        if (! $template->is_active) {
            return;
        }

        $template->load('bills');

        $months = $this->monthsForUser($template->user_id);
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
                $this->copyBillsIfUncustomized($existing->fresh(), $template);

                continue;
            }

            $occurrence = PlannedOccurrence::query()->create($attributes);
            $this->copyBillsIfUncustomized($occurrence, $template);
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
     * @return list<CarbonInterface>
     */
    protected function monthsForUser(int $userId): array
    {
        $start = Carbon::now()->startOfMonth()->subMonth()->startOfDay();
        $currentYear = BudgetYear::query()
            ->where('user_id', $userId)
            ->where('is_current', true)
            ->first();

        $end = $currentYear !== null
            ? $currentYear->endsOnExclusive()
            : Carbon::now()->startOfMonth()->addYear();

        if ($end->lte($start)) {
            $end = $start->copy()->addYear();
        }

        $months = [];
        $cursor = $start->copy();

        while ($cursor->lt($end)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }

    protected function copyBillsIfUncustomized(PlannedOccurrence $occurrence, PlannedTemplate $template): void
    {
        if ($occurrence->bills_customized || $occurrence->isResolved()) {
            return;
        }

        $occurrence->bills()->delete();

        foreach ($template->bills as $bill) {
            $occurrence->bills()->create([
                'category_id' => $bill->category_id,
                'expected_amount' => $bill->expected_amount,
                'source_template_bill_id' => $bill->id,
            ]);
        }
    }
}
