<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Reconciliation\PendingSpendMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchPendingSpends implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public ?int $importBatchId = null,
    ) {}

    /**
     * @return array{matched: int, ambiguous: int, flagged: int}
     */
    public function handle(PendingSpendMatcher $matcher): array
    {
        $result = $matcher->matchForUser($this->userId);
        $flagged = 0;

        if ($this->importBatchId !== null) {
            $batch = ImportBatch::query()->find($this->importBatchId);

            if ($batch !== null) {
                $flagged = $matcher->promoteUnmatchedAfterImport($batch)['flagged'];
            }
        }

        return [
            'matched' => $result['matched'],
            'ambiguous' => $result['ambiguous'],
            'flagged' => $flagged,
        ];
    }
}
