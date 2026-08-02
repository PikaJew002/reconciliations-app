<?php

namespace App\Services\Imports;

use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Services\Imports\Concerns\ReadsCsv;
use App\Services\Imports\Contracts\Importer;
use RuntimeException;

class BankTransactionImporter implements Importer
{
    use ReadsCsv;

    public function import(ImportBatch $batch): int
    {
        $accountId = $batch->metadata['account_id'] ?? null;

        if (! $accountId) {
            throw new RuntimeException('Bank transaction imports require metadata.account_id.');
        }

        $created = 0;

        foreach ($this->rows($batch->storage_path) as $row) {
            $attributes = $this->mapRow($row);

            if ($attributes === null) {
                continue;
            }

            BankTransaction::create([
                ...$attributes,
                'user_id' => $batch->user_id,
                'import_batch_id' => $batch->id,
                'account_id' => $accountId,
                'status' => 'unmatched',
                'metadata' => $row,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Map a CSV row to BankTransaction attributes.
     *
     * Field matching is intentionally left empty until bank-specific
     * column mapping (e.g. Chase) is implemented.
     *
     * Expected keys when implemented: external_id, posted_at, transaction_date,
     * description, normalized_description, amount, currency.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapRow(array $row): ?array
    {
        // TODO: Map CSV columns to bank transaction fields.
        return null;
    }
}
