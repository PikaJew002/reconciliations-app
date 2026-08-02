<?php

namespace App\Services\Imports\Contracts;

use App\Models\ImportBatch;

interface Importer
{
    /**
     * Process the import batch and return the number of records created.
     */
    public function import(ImportBatch $batch): int;
}
