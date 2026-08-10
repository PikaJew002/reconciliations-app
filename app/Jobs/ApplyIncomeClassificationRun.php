<?php

namespace App\Jobs;

use App\Models\CategorizationRun;
use App\Services\Reconciliation\IncomeClassificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyIncomeClassificationRun implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $categorizationRunId) {}

    public function handle(IncomeClassificationService $incomeClassification): void
    {
        $run = CategorizationRun::query()->find($this->categorizationRunId);

        if (! $run || ! $run->isInProgress()) {
            return;
        }

        $run->markProcessing();

        try {
            $result = $incomeClassification->classifyForUser($run->user_id);

            $run->markCompleted([
                'applied' => $result['learned'],
                'suggested' => $result['suggested'],
            ]);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
