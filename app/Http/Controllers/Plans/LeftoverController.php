<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Services\Plans\PaycheckLeftoverService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeftoverController extends Controller
{
    public function current(Request $request, PaycheckLeftoverService $leftover): JsonResponse
    {
        $window = $leftover->current($request->user()->id) ?? [];

        return response()->json([
            'remaining' => $this->formatRemaining($window['remaining'] ?? null),
            'days_remaining' => $window['days_remaining'] ?? null,
            'next_paycheck' => $this->formatNextPaycheck($window['ends_before'] ?? null),
        ]);
    }

    protected function formatRemaining(mixed $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $formatted = '$'.number_format(abs((float) $amount), 2);

        return (float) $amount < 0 ? '-'.$formatted : $formatted;
    }

    protected function formatNextPaycheck(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date)->format('M j');
    }
}
