<?php

namespace App\Services\Plaid;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PlaidHistoryWindow
{
    public const MAX_DAYS = 730;

    /**
     * @return array{days_requested: int, import_from: string}
     */
    public function resolve(bool $importHistory, ?string $importFrom, CarbonInterface $today, bool $production): array
    {
        $today = Carbon::parse($today)->startOfDay();

        if (! $importHistory) {
            return [
                'days_requested' => 1,
                'import_from' => $today->toDateString(),
            ];
        }

        $start = Carbon::parse($importFrom ?? $today)->startOfDay();

        if ($start->greaterThan($today)) {
            $start = $today->copy();
        }

        $days = (int) round(abs($start->diffInDays($today)));

        if ($days > self::MAX_DAYS) {
            $days = self::MAX_DAYS;
            $start = $today->copy()->subDays(self::MAX_DAYS);
        }

        if ($days < 1) {
            $days = 1;
        }

        $daysRequested = $production && $days < 30 ? 30 : $days;

        return [
            'days_requested' => $daysRequested,
            'import_from' => $start->toDateString(),
        ];
    }
}
