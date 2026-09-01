<?php

namespace App\Services\Review;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class ReviewWeek
{
    /**
     * @return array{
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     week: string,
     *     label: string,
     *     previous_week: string,
     *     next_week: ?string,
     *     is_complete: bool
     * }
     */
    public function resolve(?string $week, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $currentSunday = $today->copy()->startOfWeek(Carbon::SUNDAY);
        $lastCompleteFrom = $currentSunday->copy()->subWeek();

        $from = $week !== null && $week !== ''
            ? Carbon::parse($week)->startOfDay()->startOfWeek(Carbon::SUNDAY)
            : $lastCompleteFrom->copy();

        if ($from->gt($currentSunday)) {
            $from = $currentSunday->copy();
        }

        return $this->window($from, $currentSunday);
    }

    public function monthForWeek(CarbonInterface $from, CarbonInterface $to): string
    {
        $counts = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lt($to)) {
            $key = $cursor->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $cursor->addDay();
        }

        if ($counts === []) {
            return $from->format('Y-m');
        }

        $max = max($counts);
        $candidates = array_keys(array_filter(
            $counts,
            fn (int $count): bool => $count === $max,
        ));
        sort($candidates);

        return (string) $candidates[array_key_last($candidates)];
    }

    /**
     * @return array{
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     week: string,
     *     label: string,
     *     previous_week: string,
     *     next_week: ?string,
     *     is_complete: bool
     * }
     */
    protected function window(CarbonInterface $from, CarbonInterface $currentSunday): array
    {
        $from = $from->copy()->startOfDay();
        $to = $from->copy()->addWeek();
        $end = $to->copy()->subDay();
        $nextFrom = $from->copy()->addWeek();

        return [
            'from' => $from,
            'to' => $to,
            'week' => $from->toDateString(),
            'label' => $this->label($from, $end),
            'previous_week' => $from->copy()->subWeek()->toDateString(),
            'next_week' => $nextFrom->lte($currentSunday) ? $nextFrom->toDateString() : null,
            'is_complete' => $from->lt($currentSunday),
        ];
    }

    protected function label(CarbonInterface $from, CarbonInterface $end): string
    {
        if ($from->year !== $end->year) {
            return $from->format('M j, Y').' – '.$end->format('M j, Y');
        }

        if ($from->month !== $end->month) {
            return $from->format('M j').' – '.$end->format('M j');
        }

        return $from->format('M j').' – '.$end->format('j');
    }
}
