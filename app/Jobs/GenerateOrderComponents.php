<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Reconciliation\OrderComponentGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateOrderComponents implements ShouldQueue
{
    use Queueable;

    public function __construct(public ImportBatch $importBatch) {}

    public function handle(OrderComponentGenerator $generator): void
    {
        $batch = $this->importBatch->fresh();

        if (! $batch || $batch->type !== 'orders') {
            return;
        }

        $generator->generateForImportBatch($batch->id);
    }
}
