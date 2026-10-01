<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Imports\ImporterResolver;
use App\Services\Imports\TillerSheetCallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportTillerTransactions implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId, public string $syncId) {}

    public function handle(ImporterResolver $resolver, TillerSheetCallback $callback): void
    {
        $batches = ImportBatch::query()
            ->where('user_id', $this->userId)
            ->where('metadata->tiller_sync_id', $this->syncId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            try {
                (new ProcessImportBatch($batch))->handle($resolver);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        try {
            $callback->send($this->userId, $this->syncId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
