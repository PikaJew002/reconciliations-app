<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Imports\ImporterResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        }
    }
}
