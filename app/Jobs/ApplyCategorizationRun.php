<?php

namespace App\Jobs;

use App\Models\CategorizationRun;
use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyCategorizationRun implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $categorizationRunId) {}

    public function handle(TransactionCategorizationService $categorization): void
    {
        $run = CategorizationRun::query()->find($this->categorizationRunId);

        if (! $run || ! $run->isInProgress()) {
            return;
        }

        $run->markProcessing();

        try {
            $result = $categorization->categorizeForUser($run->user_id);

            $run->markCompleted([
                'applied' => $result['applied'],
                'ambiguous' => $result['ambiguous'],
            ]);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
