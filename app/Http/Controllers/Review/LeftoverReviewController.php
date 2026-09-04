<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Services\Plans\LeftoverOriginService;
use App\Services\Plans\PaycheckLeftoverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeftoverReviewController extends Controller
{
    public function show(
        Request $request,
        PaycheckLeftoverService $leftover,
        LeftoverOriginService $origin,
    ): Response|RedirectResponse {
        if ($this->hasSundayQuery($request)) {
            return redirect()->route('review.sunday', $request->only([
                'week',
                'act',
                'item',
                'pass',
            ]));
        }

        $userId = $request->user()->id;
        $windows = $leftover->windows($userId);
        $current = $leftover->current($userId);
        $currentOccurrenceId = isset($current['paycheck']['occurrence_id'])
            ? (int) $current['paycheck']['occurrence_id']
            : null;
        $selectedOccurrenceId = $this->selectedOccurrenceId(
            $request,
            $windows,
            $currentOccurrenceId,
        );

        return Inertia::render('Review/Leftover', [
            'windows' => $this->annotateWindows(
                $windows,
                $currentOccurrenceId,
                $selectedOccurrenceId,
            ),
            'selected_occurrence_id' => $selectedOccurrenceId,
            'leftover_origin' => $origin->payload($userId),
        ]);
    }

    protected function hasSundayQuery(Request $request): bool
    {
        return $request->filled('week')
            || $request->filled('act')
            || $request->filled('item')
            || $request->filled('pass');
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     */
    protected function selectedOccurrenceId(
        Request $request,
        array $windows,
        ?int $currentOccurrenceId,
    ): ?int {
        if (! $request->filled('occurrence')) {
            return $currentOccurrenceId;
        }

        $requestedId = $request->integer('occurrence');
        $exists = collect($windows)->contains(
            fn (array $window) => (int) $window['paycheck']['occurrence_id'] === $requestedId,
        );

        return $exists ? $requestedId : $currentOccurrenceId;
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return list<array<string, mixed>>
     */
    protected function annotateWindows(
        array $windows,
        ?int $currentOccurrenceId,
        ?int $selectedOccurrenceId,
    ): array {
        return array_map(function (array $window) use ($currentOccurrenceId, $selectedOccurrenceId) {
            $occurrenceId = (int) $window['paycheck']['occurrence_id'];
            $window['is_current'] = $currentOccurrenceId !== null
                && $occurrenceId === $currentOccurrenceId;
            $window['is_selected'] = $selectedOccurrenceId !== null
                && $occurrenceId === $selectedOccurrenceId;

            return $window;
        }, $windows);
    }
}
