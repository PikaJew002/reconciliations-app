<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Services\Imports\Contracts\Importer;
use InvalidArgumentException;

class ImporterResolver
{
    public function resolve(ImportBatch $batch): Importer
    {
        return match ([$batch->source, $batch->type]) {
            ['bank', 'transactions'] => app(BankTransactionImporter::class),
            ['walmart', 'orders'] => app(WalmartOrderImporter::class),
            default => throw new InvalidArgumentException(
                "No importer registered for source [{$batch->source}] and type [{$batch->type}].",
            ),
        };
    }
}
