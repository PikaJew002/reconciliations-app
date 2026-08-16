<?php

namespace App\Jobs;

use App\Models\PlannedOccurrenceMatchRun;
use App\Services\Plans\PlannedOccurrenceMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class MatchPlannedOccurrences implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public ?int $matchRunId = null,
    ) {}

    public function handle(PlannedOccurrenceMatcher $matcher): void
    {
        $run = $this->matchRunId !== null
            ? PlannedOccurrenceMatchRun::query()->find($this->matchRunId)
            : null;

        if ($run !== null && ! $run->isInProgress()) {
            return;
        }

        $run?->markProcessing();

        try {
            $result = $matcher->matchForUser($this->userId);

            $run?->markCompleted([
                'matched' => $result['matched'],
            ]);
        } catch (Throwable $exception) {
            $run?->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
