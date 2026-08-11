<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Imports\ImporterResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public function __construct(public ImportBatch $importBatch) {}

    public function handle(ImporterResolver $resolver): void
    {
        $batch = $this->importBatch->fresh();

        if (! $batch) {
            return;
        }

        $batch->markProcessing();

        try {
            $importer = $resolver->resolve($batch);
            $recordCount = $importer->import($batch);

            $batch->update(['record_count' => $recordCount]);
            $batch->markCompleted();
        } catch (Throwable $exception) {
            $batch->markFailed($exception->getMessage());

            return;
        }

        $jobs = [];

        if ($batch->source === 'bank' && $batch->type === 'transactions') {
            $jobs[] = new PairCreditCardPayments($batch->user_id);
            $jobs[] = new PairTransfers($batch->user_id);
            $jobs[] = new CategorizeTransactions($batch->user_id);
        }

        $jobs = [
            ...$jobs,
            new GenerateOrderComponents($batch),
            new MatchMerchants($batch->user_id),
            new RunReconciliation($batch->user_id),
        ];

        Bus::chain($jobs)->dispatch();
    }
}
